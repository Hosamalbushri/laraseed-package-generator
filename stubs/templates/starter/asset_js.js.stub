/**
 * Laraseed Web Starter — Frontend Controller & Accessibility Kernel
 *
 * Implements strict CSP-compliant event delegation, keyboard navigation,
 * focus trapping, ARIA management, and secure cookie hygiene.
 */

class WebStarterKernel {
    constructor() {
        this.focusStack = [];
        this.init();
    }

    init() {
        if (window.__laraseed_starter_initialized) {
            return;
        }
        window.__laraseed_starter_initialized = true;

        this.initDarkMode();
        this.initGlobalDelegation();
        this.initKeyboardListeners();
    }

    get isDarkMode() {
        return document.documentElement.classList.contains('dark');
    }

    setDarkMode(isDark) {
        document.documentElement.classList.toggle('dark', isDark);
        const secureFlag = window.location.protocol === 'https:' ? '; Secure' : '';
        document.cookie = `dark_mode=${isDark ? '1' : '0'}; path=/; max-age=31536000; SameSite=Lax${secureFlag}`;
        try {
            localStorage.setItem('dark_mode', isDark ? '1' : '0');
        } catch (e) {}

        window.dispatchEvent(new CustomEvent('laraseed:themechange', {
            detail: { isDarkMode: isDark }
        }));
    }

    toggleDarkMode() {
        this.setDarkMode(!this.isDarkMode);
    }

    initDarkMode() {
        const hasDarkClass = document.documentElement.classList.contains('dark');
        if (!document.cookie.includes('dark_mode=') && hasDarkClass) {
            this.setDarkMode(true);
        }
    }

    initGlobalDelegation() {
        document.addEventListener('click', (e) => {
            // 1. Dark Mode Toggle
            const themeBtn = e.target.closest('[data-action="toggle-dark-mode"], #theme-toggle');
            if (themeBtn) {
                e.preventDefault();
                this.toggleDarkMode();
                return;
            }

            // 2. Mobile Menu Toggle
            const mobileBtn = e.target.closest('[data-action="toggle-mobile-menu"], #mobile-menu-button');
            if (mobileBtn) {
                e.preventDefault();
                const menuId = mobileBtn.getAttribute('aria-controls') || 'mobile-menu';
                const menu = document.getElementById(menuId);
                if (menu) {
                    const isExpanded = mobileBtn.getAttribute('aria-expanded') === 'true';
                    const willOpen = !isExpanded;

                    menu.classList.toggle('hidden', !willOpen);
                    mobileBtn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
                    menu.setAttribute('aria-hidden', willOpen ? 'false' : 'true');

                    if (willOpen) {
                        const firstLink = menu.querySelector('a, button');
                        if (firstLink) firstLink.focus();
                    }
                }
                return;
            }

            // 3. Open Modal
            const openModalBtn = e.target.closest('[data-action="open-modal"]');
            if (openModalBtn) {
                e.preventDefault();
                const targetId = openModalBtn.getAttribute('data-target') || openModalBtn.getAttribute('href')?.replace('#', '');
                if (targetId) {
                    this.openModal(targetId, openModalBtn);
                }
                return;
            }

            // 4. Close Modal
            const closeModalBtn = e.target.closest('[data-action="close-modal"], [data-dismiss="modal"]');
            if (closeModalBtn) {
                e.preventDefault();
                const targetId = closeModalBtn.getAttribute('data-target') || closeModalBtn.closest('[data-component="modal"]')?.id;
                if (targetId) {
                    this.closeModal(targetId);
                }
                return;
            }

            // 5. Modal Backdrop Click
            const backdropModal = e.target.closest('[data-component="modal"]');
            if (backdropModal && e.target === backdropModal) {
                this.closeModal(backdropModal.id);
            }
        });
    }

    openModal(modalId, triggerElement = null) {
        const modal = document.getElementById(modalId);
        if (!modal) return;

        const trigger = triggerElement || (document.activeElement && document.activeElement !== document.body ? document.activeElement : null);
        this.focusStack.push({ modalId: modal.id, trigger });
        modal.classList.remove('hidden');
        modal.setAttribute('aria-hidden', 'false');

        const focusable = modal.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
        if (focusable.length > 0) {
            focusable[0].focus();
        } else {
            modal.focus();
        }

        window.dispatchEvent(new CustomEvent('laraseed:modalopen', { detail: { modalId: modal.id } }));
    }

    closeModal(modalId) {
        const modal = typeof modalId === 'string' ? document.getElementById(modalId) : modalId;
        if (!modal) return;

        modal.classList.add('hidden');
        modal.setAttribute('aria-hidden', 'true');

        let restoreItem = null;
        for (let i = this.focusStack.length - 1; i >= 0; i--) {
            if (this.focusStack[i].modalId === modal.id) {
                restoreItem = this.focusStack.splice(i, 1)[0];
                break;
            }
        }
        if (!restoreItem && this.focusStack.length > 0) {
            restoreItem = this.focusStack.pop();
        }

        if (restoreItem?.trigger && typeof restoreItem.trigger.focus === 'function') {
            restoreItem.trigger.focus();
        }

        window.dispatchEvent(new CustomEvent('laraseed:modalclose', { detail: { modalId: modal.id } }));
    }

    initKeyboardListeners() {
        document.addEventListener('keydown', (e) => {
            // Escape Key Handling
            if (e.key === 'Escape' || e.key === 'Esc') {
                const openModals = document.querySelectorAll('[data-component="modal"]:not(.hidden)');
                if (openModals.length > 0) {
                    e.preventDefault();
                    this.closeModal(openModals[openModals.length - 1].id);
                    return;
                }

                const mobileMenu = document.getElementById('mobile-menu');
                const mobileBtn = document.querySelector('[data-action="toggle-mobile-menu"], #mobile-menu-button');
                if (mobileMenu && !mobileMenu.classList.contains('hidden')) {
                    e.preventDefault();
                    mobileMenu.classList.add('hidden');
                    mobileMenu.setAttribute('aria-hidden', 'true');
                    if (mobileBtn) {
                        mobileBtn.setAttribute('aria-expanded', 'false');
                        mobileBtn.focus();
                    }
                }
            }

            // Focus Trap for Open Modal
            if (e.key === 'Tab') {
                const openModals = document.querySelectorAll('[data-component="modal"]:not(.hidden)');
                if (openModals.length > 0) {
                    const openModal = openModals[openModals.length - 1];
                    const focusables = Array.from(openModal.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'))
                        .filter(el => !el.hasAttribute('disabled') && el.offsetParent !== null);

                    if (focusables.length === 0) {
                        e.preventDefault();
                        openModal.focus();
                        return;
                    }

                    const firstEl = focusables[0];
                    const lastEl = focusables[focusables.length - 1];

                    if (e.shiftKey) {
                        if (document.activeElement === firstEl || document.activeElement === openModal) {
                            e.preventDefault();
                            lastEl.focus();
                        }
                    } else {
                        if (document.activeElement === lastEl) {
                            e.preventDefault();
                            firstEl.focus();
                        }
                    }
                }
            }
        });
    }
}

const kernel = new WebStarterKernel();

window.LaraseedWeb = kernel;

export default kernel;


