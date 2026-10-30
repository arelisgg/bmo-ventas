<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
redirect_if_admin_logged_in();
$error = '';
$email = '';/*
|--------------------------------------------------------------------------
| Login
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $csrfToken =
        $_POST['csrf_token'] ?? null;    /*
|--------------------------------------------------------------------------
| CSRF Validation
|--------------------------------------------------------------------------
*/

    if (!verify_csrf_token($csrfToken)) {

        $error =
            'La solicitud no es válida. Intenta nuevamente.';

    } else {

        /*
        |--------------------------------------------------------------------------
        | Credentials
        |--------------------------------------------------------------------------
        */

        $email = strtolower(
            trim(
                (string) (
                    $_POST['email']
                    ?? ''
                )
            )
        );
        $password =
            (string) (
                $_POST['password']
                ?? ''
            );        /*
      |--------------------------------------------------------------------------
      | Required Fields
      |--------------------------------------------------------------------------
      */

        if (
            $email === '' ||
            $password === ''
        ) {

            $error =
                'El correo electrónico y la contraseña son obligatorios.';

        } elseif (login_is_locked($pdo, $email)) {

            $error =
                'Demasiados intentos fallidos. Espera ' . LOGIN_LOCK_MINUTES . ' minutos e intenta de nuevo.';

        } else {

            /*
            |--------------------------------------------------------------------------
            | Find Administrator
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare(
                'SELECT
                    id,
                    name,
                    email,
                    password,
                    is_active
                 FROM admins
                 WHERE email = :email
                 LIMIT 1'
            );
            $stmt->execute([
                'email' => $email,
            ]);
            $admin =
                $stmt->fetch();            /*
|--------------------------------------------------------------------------
| Validate Login
|--------------------------------------------------------------------------
*/

            $validLogin =
                $admin &&
                (int) $admin['is_active'] === 1 &&
                password_verify(
                    $password,
                    $admin['password']
                );
            if (!$validLogin) {

                record_login_failure($pdo, $email);

                $error =
                    'El correo electrónico o la contraseña son incorrectos.';

            } else {

                clear_login_failures($pdo, $email);

                /*
                |--------------------------------------------------------------------------
                | Update Password Hash When Necessary
                |--------------------------------------------------------------------------
                */

                if (
                    password_needs_rehash(
                        $admin['password'],
                        PASSWORD_DEFAULT
                    )
                ) {

                    $newPasswordHash =
                        password_hash(
                            $password,
                            PASSWORD_DEFAULT
                        );
                    $updatePassword =
                        $pdo->prepare(
                            'UPDATE admins
                             SET password = :password
                             WHERE id = :id'
                        );
                    $updatePassword->execute([
                        'password' =>
                            $newPasswordHash,

                        'id' =>
                            $admin['id'],
                    ]);
                }                /*
               |--------------------------------------------------------------------------
               | Regenerate Session
               |--------------------------------------------------------------------------
               */

                session_regenerate_id(
                    false
                );                /*
              |--------------------------------------------------------------------------
              | Authentication Session
              |--------------------------------------------------------------------------
              */

                $_SESSION['admin_id'] =
                    (int) $admin['id'];

                $_SESSION['admin_name'] =
                    $admin['name'];

                $_SESSION['admin_email'] =
                    $admin['email'];

                $_SESSION['admin_last_activity'] =
                    time();

                $_SESSION['admin_demo'] =
                    APP_DEMO_MODE;

                remember_demo_mode(APP_DEMO_MODE);                /*
         |--------------------------------------------------------------------------
         | Redirect
         |--------------------------------------------------------------------------
         */

                header(
                    'Location: ' .
                    APP_BASE_URL .
                    '/admin/'
                );

                exit;
            }
        }
    }
}

?>
<!DOCTYPE html>

<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        Iniciar sesión | Administración <?= e(business_name()) ?>
    </title>
    <link href="<?= e(business_favicon_url()) ?>" rel="icon">
    <link rel="preconnect" href="https://fonts.googleapis.com">

    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600&family=Inter:wght@400;500;600&display=swap"
        rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;

            min-height: 100vh;

            display: grid;
            place-items: center;

            padding: 30px;

            color: #1d1a18;

            background:
                linear-gradient(rgba(247,
                        243,
                        237,
                        0.88),
                    rgba(247,
                        243,
                        237,
                        0.88)),
                <?= setting('hero_image_path') !== '' ? 'url("' . e(setting_image_url('hero_image_path')) . '") center / cover' : 'none' ?>;

            font-family:
                "Inter",
                Arial,
                sans-serif;
        }

        .login-card {
            width: min(100%,
                    440px);

            padding:
                52px 46px;

            background:
                rgba(255,
                    255,
                    255,
                    0.96);

            box-shadow:
                0 20px 70px rgba(40,
                    33,
                    28,
                    0.12);
        }

        .logo {
            display: block;

            width: 190px;
            height: auto;

            margin:
                0 auto 36px;
        }

        h1 {
            margin:
                0 0 8px;

            text-align: center;

            font-family:
                "Cormorant Garamond",
                Georgia,
                serif;

            font-size: 2.5rem;

            font-weight: 500;
        }

        .subtitle {
            margin:
                0 0 34px;

            color: #77706a;

            text-align: center;

            font-size: 0.82rem;

            line-height: 1.6;
        }

        .form-group {
            margin-bottom: 18px;
        }

        label {
            display: block;

            margin-bottom: 7px;

            color: #514b46;

            font-size: 0.7rem;

            font-weight: 600;

            letter-spacing: 0.08em;

            text-transform: uppercase;
        }

        input {
            width: 100%;

            min-height: 48px;

            padding:
                0 13px;

            border:
                1px solid #d8d0c8;

            background: #ffffff;

            color: #1d1a18;

            font: inherit;
        }

        input:focus {
            border-color:
                #1d1a18;

            outline: none;
        }

        .login-button {
            width: 100%;

            min-height: 50px;

            margin-top: 8px;

            border:
                1px solid #1d1a18;

            background:
                #1d1a18;

            color:
                #ffffff;

            cursor: pointer;

            font: inherit;

            font-size: 0.72rem;

            font-weight: 600;

            letter-spacing: 0.14em;

            text-transform: uppercase;
        }

        .login-button:hover {
            background:
                #3b3531;
        }

        .error {
            margin-bottom: 20px;

            padding: 13px;

            background:
                #f7e9e7;

            color:
                #852f29;

            font-size: 0.8rem;

            line-height: 1.5;
        }

        .notice {
            margin-bottom: 20px;

            padding: 13px;

            background:
                #f4efe7;

            color:
                #645b54;

            font-size: 0.8rem;

            line-height: 1.5;
        }

        .login-demo-hint {
            margin: 22px 0 0;
            padding: 12px 14px;
            border: 1px dashed rgba(29, 26, 24, 0.25);
            border-radius: 10px;
            color: #7a716a;
            font-size: 0.78rem;
            line-height: 1.5;
            text-align: center;
        }

        .login-demo-hint strong {
            color: #1d1a18;
            font-weight: 600;
        }

        .back-link {
            display: block;

            margin-top: 28px;

            color:
                #77706a;

            text-align: center;

            text-decoration: none;

            font-size: 0.75rem;
        }

        .back-link:hover {
            color:
                #1d1a18;
        }

        @media (max-width: 540px) {

            body {
                padding: 15px;
            }

            .login-card {
                padding:
                    42px 24px;
            }

        }
    </style>

</head>

<body>
    <main class="login-card"> <img class="logo" src="<?= e(business_logo_url()) ?>" alt="<?= e(business_name()) ?>">
        <h1>
            Administración
        </h1>
        <p class="subtitle">
            Inicia sesión para administrar
            el catálogo de <?= e(business_name()) ?>.
        </p>
        <?php if (
            isset(
            $_GET['expired']
        )
        ): ?>

            <div class="notice">

                Tu sesión ha expirado.
                Inicia sesión nuevamente.

            </div>

        <?php endif; ?>
        <?php if (
            $error !== ''
        ): ?>

            <div class="error">

                <?= e($error) ?>

            </div>

        <?php endif; ?>
        <form method="post"> <input type="hidden" name="csrf_token" value="<?= e(
            csrf_token()
        ) ?>">
            <div class="form-group">

                <label for="email">
                    Correo electrónico
                </label>

                <input id="email" name="email" type="email" value="<?= e($email) ?>" required autocomplete="email">

            </div>
            <div class="form-group">

                <label for="password">
                    Contraseña
                </label>

                <input id="password" name="password" type="password" required autocomplete="current-password">

            </div>
            <button class="login-button" type="submit">
                INICIAR SESIÓN
            </button>
        </form>
<?php if (DEMO_ENABLED): ?>
        <p class="login-demo-hint">
            ¿Quieres ver el sistema? Entra con <strong><?= e(DEMO_EMAIL) ?></strong> y la contraseña <strong>demo1234</strong>.
        </p>
<?php endif; ?>
        <a class="back-link" href="<?= e(APP_BASE_URL) ?>/">
            ← Volver al catálogo
        </a>
    </main>
</body>

</html>