/* ArenaKu — interaksi UI tanpa dependensi. */
(function () {
    'use strict';

    var header = document.querySelector('.site-header');

    if (header) {
        var navToggle = header.querySelector('.nav-toggle');
        var navCollapse = header.querySelector('.nav-collapse');

        if (navToggle && navCollapse) {
            navToggle.addEventListener('click', function () {
                var open = header.classList.toggle('is-nav-open');
                navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && header.classList.contains('is-nav-open')) {
                    header.classList.remove('is-nav-open');
                    navToggle.setAttribute('aria-expanded', 'false');
                    navToggle.focus();
                }
            });

            window.addEventListener('resize', function () {
                if (window.innerWidth > 960 && header.classList.contains('is-nav-open')) {
                    header.classList.remove('is-nav-open');
                    navToggle.setAttribute('aria-expanded', 'false');
                }
            });
        }
    }

    var accountMenus = document.querySelectorAll('.account-menu');

    if (accountMenus.length) {
        document.addEventListener('click', function (event) {
            accountMenus.forEach(function (menu) {
                if (menu.hasAttribute('open') && !menu.contains(event.target)) {
                    menu.removeAttribute('open');
                }
            });
        });

        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') {
                return;
            }
            accountMenus.forEach(function (menu) {
                if (menu.hasAttribute('open')) {
                    menu.removeAttribute('open');
                    var summary = menu.querySelector('summary');
                    if (summary) {
                        summary.focus();
                    }
                }
            });
        });
    }

    document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
        var input = button.parentElement.querySelector('input');
        if (!input) {
            return;
        }

        var show = button.querySelector('.pw-show');
        var hide = button.querySelector('.pw-hide');

        button.addEventListener('click', function () {
            var reveal = input.type === 'password';
            input.type = reveal ? 'text' : 'password';
            button.setAttribute('aria-label', reveal ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi');
            button.setAttribute('aria-pressed', reveal ? 'true' : 'false');

            if (show && hide) {
                show.hidden = reveal;
                hide.hidden = !reveal;
            }
        });
    });

    document.querySelectorAll('[data-copy]').forEach(function (button) {
        var label = button.querySelector('[data-copy-label]');
        var original = label ? label.textContent : '';

        button.addEventListener('click', function () {
            var value = button.getAttribute('data-copy') || '';

            function done() {
                if (label) {
                    label.textContent = 'Tersalin';
                }
                button.classList.add('is-copied');
                window.setTimeout(function () {
                    if (label) {
                        label.textContent = original;
                    }
                    button.classList.remove('is-copied');
                }, 1800);
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(value).then(done).catch(fallback);
            } else {
                fallback();
            }

            function fallback() {
                var helper = document.createElement('textarea');
                helper.value = value;
                helper.setAttribute('readonly', '');
                helper.style.position = 'fixed';
                helper.style.opacity = '0';
                document.body.appendChild(helper);
                helper.select();
                try {
                    document.execCommand('copy');
                    done();
                } catch (error) {
                    /* Diamkan bila browser menolak akses clipboard. */
                }
                document.body.removeChild(helper);
            }
        });
    });

    var adminBody = document.querySelector('.admin-body');
    var adminBurger = document.querySelector('.admin-burger');

    if (adminBody && adminBurger) {
        function closeSidebar() {
            adminBody.classList.remove('is-sidebar-open');
            adminBurger.setAttribute('aria-expanded', 'false');
        }

        adminBurger.addEventListener('click', function () {
            var open = adminBody.classList.toggle('is-sidebar-open');
            adminBurger.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        var overlay = document.querySelector('.admin-overlay button');
        if (overlay) {
            overlay.addEventListener('click', closeSidebar);
        }

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeSidebar();
            }
        });
    }

    document.querySelectorAll('[data-upload-preview]').forEach(function (wrap) {
        var input = wrap.querySelector('[data-upload-input]');
        var preview = wrap.querySelector('.upload-preview');
        var image = preview ? preview.querySelector('img') : null;
        var placeholder = preview ? preview.querySelector('.upload-placeholder') : null;
        var reset = wrap.querySelector('[data-upload-reset]');
        var error = wrap.querySelector('[data-upload-error]');
        var title = wrap.querySelector('.upload-title');

        if (!input || !image) {
            return;
        }

        var originalSrc = image.getAttribute('src') || '';
        var originalTitle = title ? title.textContent : '';
        var objectUrl = '';

        function showError(message) {
            if (!error) {
                return;
            }
            error.textContent = message;
            error.hidden = !message;
        }

        function syncState(src, hasImage) {
            if (src) {
                image.src = src;
                image.hidden = false;
            } else {
                image.removeAttribute('src');
                image.hidden = true;
            }
            if (preview) {
                preview.classList.toggle('has-image', hasImage);
            }
            if (placeholder) {
                placeholder.hidden = hasImage;
            }
        }

        input.addEventListener('change', function () {
            showError('');

            var file = input.files && input.files[0];
            if (!file) {
                return;
            }

            if (!/^image\/(jpeg|png|webp)$/i.test(file.type)) {
                input.value = '';
                showError('Format foto harus JPG, JPEG, PNG, atau WEBP.');
                return;
            }

            if (file.size > 3 * 1024 * 1024) {
                input.value = '';
                showError('Ukuran foto maksimal 3 MB.');
                return;
            }

            if (objectUrl) {
                URL.revokeObjectURL(objectUrl);
            }
            objectUrl = URL.createObjectURL(file);
            syncState(objectUrl, true);

            if (reset) {
                reset.hidden = false;
            }
            if (title) {
                title.textContent = 'Foto baru dipilih';
            }
        });

        if (reset) {
            reset.addEventListener('click', function () {
                input.value = '';
                if (objectUrl) {
                    URL.revokeObjectURL(objectUrl);
                    objectUrl = '';
                }

                syncState(originalSrc, originalSrc !== '');
                reset.hidden = true;
                showError('');

                if (title) {
                    title.textContent = originalTitle;
                }
            });
        }
    });
})();
