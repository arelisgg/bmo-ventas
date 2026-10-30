<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../sales/_layout.php';

require_admin();


/*
|--------------------------------------------------------------------------
| Users
|--------------------------------------------------------------------------
|
| Solo el dueño entra aquí (ver admin_role_sections()). Crea usuarios,
| cambia su rol o contraseña y los desactiva. Siempre queda al menos un
| dueño activo.
|
*/

$rolesReady = admin_roles_column_exists($pdo);
$roles = admin_roles();
$error = '';
$editId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;

$form = [
    'name' => '',
    'email' => '',
    'role' => 'seller',
    'is_active' => '1',
];

function active_owner_count(PDO $pdo, int $exceptId): int
{
    $statement = $pdo->prepare(
        "SELECT COUNT(*) FROM admins WHERE role = 'owner' AND is_active = 1 AND id <> :id"
    );
    $statement->execute(['id' => $exceptId]);

    return (int) $statement->fetchColumn();
}

function find_admin_user(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare('SELECT id, name, email, role, is_active FROM admins WHERE id = :id');
    $statement->execute(['id' => $id]);

    return $statement->fetch() ?: null;
}

if ($editId !== null && $rolesReady) {
    $editUser = find_admin_user($pdo, $editId);

    if ($editUser === null) {
        $editId = null;
    } else {
        $form = [
            'name' => $editUser['name'],
            'email' => $editUser['email'],
            'role' => $editUser['role'],
            'is_active' => (string) $editUser['is_active'],
        ];
    }
}


/*
|--------------------------------------------------------------------------
| Save
|--------------------------------------------------------------------------
*/

if ($rolesReady && $_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['name', 'email', 'role'] as $field) {
        $value = $_POST[$field] ?? '';
        $form[$field] = is_string($value) ? trim($value) : '';
    }

    $form['email'] = strtolower($form['email']);
    $form['is_active'] = ($_POST['is_active'] ?? '1') === '1' ? '1' : '0';
    $password = (string) ($_POST['password'] ?? '');

    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Token de seguridad inválido. Recarga la página e intenta de nuevo.';
    } elseif ($form['name'] === '' || mb_strlen($form['name']) > 120) {
        $error = 'Escribe el nombre del usuario.';
    } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($form['email']) > 190) {
        $error = 'El correo no es válido.';
    } elseif (!isset($roles[$form['role']])) {
        $error = 'Elige un rol.';
    } elseif (($editId === null || $password !== '') && mb_strlen($password) < 8) {
        $error = 'La contraseña debe tener al menos 8 caracteres.';
    } elseif (
        $editId !== null &&
        ($form['role'] !== 'owner' || $form['is_active'] !== '1') &&
        active_owner_count($pdo, $editId) === 0
    ) {
        $error = 'Debe quedar al menos un dueño activo.';
    } else {
        $duplicate = $pdo->prepare('SELECT id FROM admins WHERE email = :email AND id <> :id');
        $duplicate->execute(['email' => $form['email'], 'id' => $editId ?? 0]);

        if ($duplicate->fetch()) {
            $error = 'Ya existe un usuario con ese correo.';
        }
    }

    if ($error === '') {
        $values = [
            'name' => $form['name'],
            'email' => $form['email'],
            'role' => $form['role'],
            'is_active' => (int) $form['is_active'],
        ];

        if ($editId === null) {
            $pdo->prepare(
                'INSERT INTO admins (name, email, password, role, is_active)
                 VALUES (:name, :email, :password, :role, :is_active)'
            )->execute($values + ['password' => password_hash($password, PASSWORD_DEFAULT)]);
        } else {
            $passwordSql = $password !== '' ? ', password = :password' : '';

            if ($password !== '') {
                $values['password'] = password_hash($password, PASSWORD_DEFAULT);
            }

            $pdo->prepare(
                'UPDATE admins
                 SET name = :name, email = :email, role = :role, is_active = :is_active' . $passwordSql . '
                 WHERE id = :id'
            )->execute($values + ['id' => $editId]);
        }

        header('Location: ' . APP_BASE_URL . '/admin/users/?saved=1');
        exit;
    }
}

$users = $rolesReady
    ? $pdo->query('SELECT id, name, email, role, is_active FROM admins ORDER BY is_active DESC, name ASC')->fetchAll()
    : [];

render_sales_layout_start('Usuarios', 'users');

?>

<div class="page-header">
    <div>
        <span class="page-eyebrow">CONFIGURACIÓN</span>
        <h1 class="page-title">Usuarios</h1>
        <p class="page-description">
            Quién entra al panel y qué puede ver. El vendedor y bodega no ven costos, finanzas ni configuración.
        </p>
    </div>
</div>

<?php if (!$rolesReady): ?>

    <section class="empty-state">
        <h2>Falta agregar los roles de usuario</h2>
        <p>
            En <a href="<?= e(APP_BASE_URL) ?>/admin/settings/">Configuración</a> pulsa
            <strong>ACTUALIZAR BASE DE DATOS</strong> (solo el dueño puede hacerlo). Luego recarga esta página.
        </p>
    </section>

<?php else: ?>

    <?php if (isset($_GET['saved'])): ?>
        <div class="admin-notice admin-notice-success">Usuario guardado.</div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="admin-notice admin-notice-error"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="dashboard-panel settings-panel">
        <div class="dashboard-panel-header">
            <div>
                <span class="page-eyebrow"><?= $editId === null ? 'NUEVO USUARIO' : 'EDITAR USUARIO' ?></span>
                <h2><?= $editId === null ? 'Agregar usuario' : e($form['name']) ?></h2>
            </div>
            <?php if ($editId !== null): ?>
                <a class="admin-button admin-button-outline" href="<?= e(APP_BASE_URL) ?>/admin/users/">CANCELAR</a>
            <?php endif; ?>
        </div>

        <form method="post" action="<?= e(APP_BASE_URL) ?>/admin/users/<?= $editId !== null ? '?edit=' . (int) $editId : '' ?>">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label" for="name">Nombre</label>
                    <input class="form-input" id="name" name="name" maxlength="120" required value="<?= e($form['name']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="email">Correo (para entrar)</label>
                    <input class="form-input" id="email" name="email" type="email" maxlength="190" required
                        value="<?= e($form['email']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="role">Rol</label>
                    <select class="form-select" id="role" name="role">
                        <?php foreach ($roles as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $form['role'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="form-hint">Dueño: todo. Vendedor: ventas, productos, inventario y taller. Bodega: productos, categorías, inventario y taller.</p>
                </div>
                <div class="form-group">
                    <label class="form-label" for="password"><?= $editId === null ? 'Contraseña' : 'Nueva contraseña (opcional)' ?></label>
                    <input class="form-input" id="password" name="password" type="password" minlength="8"
                        autocomplete="new-password" <?= $editId === null ? 'required' : '' ?>>
                    <p class="form-hint">Mínimo 8 caracteres.<?= $editId !== null ? ' Déjala vacía para no cambiarla.' : '' ?></p>
                </div>
                <div class="form-group">
                    <label class="form-label" for="is_active">Estado</label>
                    <select class="form-select" id="is_active" name="is_active">
                        <option value="1" <?= $form['is_active'] === '1' ? 'selected' : '' ?>>Activo</option>
                        <option value="0" <?= $form['is_active'] !== '1' ? 'selected' : '' ?>>Desactivado (no puede entrar)</option>
                    </select>
                </div>
            </div>

            <div class="settings-actions users-form-actions">
                <button class="admin-button" type="submit"><?= $editId === null ? 'AGREGAR USUARIO' : 'GUARDAR CAMBIOS' ?></button>
            </div>
        </form>
    </section>

    <section class="dashboard-panel settings-panel">
        <div class="dashboard-panel-header">
            <div>
                <span class="page-eyebrow">EQUIPO</span>
                <h2>Usuarios del panel</h2>
            </div>
        </div>

        <div class="admin-table-wrapper">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Correo</th>
                        <th>Rol</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?= e($user['name']) ?></td>
                            <td><?= e($user['email']) ?></td>
                            <td><?= e(admin_role_label($user['role'])) ?></td>
                            <td><?= (int) $user['is_active'] === 1 ? 'Activo' : 'Desactivado' ?></td>
                            <td>
                                <a class="table-action-link" href="<?= e(APP_BASE_URL) ?>/admin/users/?edit=<?= (int) $user['id'] ?>">Editar</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

<?php endif; ?>

<?php render_sales_layout_end(); ?>
