// Mobile helpers for the admin pages.
document.addEventListener('DOMContentLoaded', function () {
    // Label each table cell with its column title, so phones can show rows as cards.
    document.querySelectorAll('table.admin-table').forEach(function (table) {
        var titles = Array.prototype.map.call(table.querySelectorAll('thead th'), function (th) {
            return th.textContent.trim();
        });

        table.querySelectorAll('tbody tr').forEach(function (row) {
            Array.prototype.forEach.call(row.children, function (cell, index) {
                if (titles[index] && !cell.hasAttribute('data-label')) {
                    cell.setAttribute('data-label', titles[index]);
                }

                if (cell.textContent.trim() === '' && !cell.querySelector('img, input, select, button, a')) {
                    cell.setAttribute('data-empty', '');
                }
            });
        });
    });

    // Row actions: the first one is a button; with three or more, the rest go in a ⋯ menu.
    var openMenu = null;

    var closeMenu = function () {
        if (openMenu) {
            openMenu.classList.remove('is-open');
            openMenu.querySelector('.row-actions-toggle').setAttribute('aria-expanded', 'false');
            openMenu = null;
        }
    };

    document.querySelectorAll('table.admin-table tbody td').forEach(function (cell) {
        var items = [];

        cell.querySelectorAll('.table-action-link').forEach(function (link) {
            var item = link.closest('form') || link;

            if (cell.contains(item) && items.indexOf(item) === -1) {
                items.push(item);
            }
        });

        if (items.length === 0) {
            return;
        }

        var bar = document.createElement('div');
        bar.className = 'row-actions';

        items.forEach(function (item, index) {
            var link = item.matches('.table-action-link') ? item : item.querySelector('.table-action-link');
            link.textContent = link.textContent.trim();

            if (index === 0 || items.length < 3) {
                link.classList.add('row-action-button');

                if (index === 0) {
                    link.classList.add('row-action-primary');
                }

                bar.appendChild(item);
            }
        });

        if (items.length >= 3) {
            var wrap = document.createElement('div');
            wrap.className = 'row-actions-more';
            wrap.innerHTML = '<button type="button" class="row-actions-toggle" aria-label="Más acciones" aria-expanded="false">'
                + '<span></span><span></span><span></span></button><div class="row-actions-menu" role="menu"></div>';

            var menu = wrap.querySelector('.row-actions-menu');

            items.slice(1).forEach(function (item) {
                var link = item.matches('.table-action-link') ? item : item.querySelector('.table-action-link');
                link.classList.add('row-action-item');
                link.setAttribute('role', 'menuitem');
                menu.appendChild(item);
            });

            wrap.querySelector('.row-actions-toggle').addEventListener('click', function (event) {
                event.stopPropagation();

                if (openMenu === wrap) {
                    closeMenu();
                    return;
                }

                closeMenu();

                // Fixed position so table scroll containers don't clip the menu.
                var rect = this.getBoundingClientRect();
                wrap.classList.add('is-open');
                var below = rect.bottom + 6 + menu.offsetHeight <= window.innerHeight;
                menu.style.top = (below ? rect.bottom + 6 : rect.top - 6 - menu.offsetHeight) + 'px';
                menu.style.left = Math.max(8, rect.right - menu.offsetWidth) + 'px';
                this.setAttribute('aria-expanded', 'true');
                openMenu = wrap;
            });

            bar.appendChild(wrap);
        }

        var holder = cell.querySelector('.product-row-actions') || cell;
        holder.innerHTML = '';
        holder.appendChild(bar);
        cell.classList.add('row-actions-cell');
    });

    document.addEventListener('click', function (event) {
        if (openMenu && !openMenu.contains(event.target)) {
            closeMenu();
        }
    });
    window.addEventListener('scroll', closeMenu, true);
    window.addEventListener('resize', closeMenu);
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeMenu();
        }
    });

    // Pagination: long tables show 20 rows per page with a pager under them.
    var PAGE_SIZE = 20;

    document.querySelectorAll('table.admin-table').forEach(function (table, tableIndex) {
        var body = table.tBodies[0];
        var rows = body ? Array.prototype.slice.call(body.rows) : [];

        if (rows.length <= PAGE_SIZE) {
            return;
        }

        var pages = Math.ceil(rows.length / PAGE_SIZE);
        var storageKey = 'admin-page:' + location.pathname + location.search + ':' + tableIndex;
        var current = 1;

        try {
            current = parseInt(sessionStorage.getItem(storageKey), 10) || 1;
        } catch (error) {}

        var pager = document.createElement('nav');
        pager.className = 'admin-pager';
        pager.setAttribute('aria-label', 'Paginación');

        var anchorNode = table.closest('.admin-table-wrapper') || table;
        anchorNode.parentNode.insertBefore(pager, anchorNode.nextSibling);

        var pageList = function () {
            // First, last, and two around the current page; gaps become "…".
            var list = [];

            for (var page = 1; page <= pages; page++) {
                if (page === 1 || page === pages || Math.abs(page - current) <= 1) {
                    list.push(page);
                } else if (list[list.length - 1] !== '…') {
                    list.push('…');
                }
            }

            return list;
        };

        var show = function (page, scroll) {
            current = Math.min(Math.max(page, 1), pages);

            rows.forEach(function (row, index) {
                row.hidden = Math.floor(index / PAGE_SIZE) + 1 !== current;
            });

            try {
                sessionStorage.setItem(storageKey, String(current));
            } catch (error) {}

            var first = (current - 1) * PAGE_SIZE + 1;
            var last = Math.min(current * PAGE_SIZE, rows.length);
            var html = '<span class="admin-pager-info">' + first + '–' + last + ' de ' + rows.length + '</span>'
                + '<div class="admin-pager-pages">'
                + '<button type="button" data-page="' + (current - 1) + '"' + (current === 1 ? ' disabled' : '') + ' aria-label="Anterior">‹</button>';

            pageList().forEach(function (item) {
                html += item === '…'
                    ? '<span class="admin-pager-gap">…</span>'
                    : '<button type="button" data-page="' + item + '"' + (item === current ? ' class="is-current" aria-current="page"' : '') + '>' + item + '</button>';
            });

            html += '<button type="button" data-page="' + (current + 1) + '"' + (current === pages ? ' disabled' : '') + ' aria-label="Siguiente">›</button></div>';
            pager.innerHTML = html;

            if (scroll) {
                table.scrollIntoView({ block: 'start', behavior: 'smooth' });
            }
        };

        pager.addEventListener('click', function (event) {
            var button = event.target.closest('button[data-page]');

            if (button && !button.disabled) {
                show(parseInt(button.getAttribute('data-page'), 10), true);
            }
        });

        show(current, false);
    });

    // On phones the sidebar slides in from the left with the ☰ button.
    var header = document.querySelector('.admin-header');
    var sidebar = document.querySelector('.admin-layout .sidebar');

    if (header && sidebar) {
        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'admin-menu-toggle';
        toggle.setAttribute('aria-label', 'Abrir menú');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.innerHTML = '<span></span>';
        header.insertBefore(toggle, header.firstChild);

        var backdrop = document.createElement('div');
        backdrop.className = 'admin-menu-backdrop';
        document.body.appendChild(backdrop);

        var setOpen = function (open) {
            document.body.classList.toggle('admin-menu-open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        };

        toggle.addEventListener('click', function () {
            setOpen(!document.body.classList.contains('admin-menu-open'));
        });
        backdrop.addEventListener('click', function () {
            setOpen(false);
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        });
    }
});
