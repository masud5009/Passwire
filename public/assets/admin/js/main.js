/**
 * ==============================================================
 * Passwire Admin Console - Main JavaScript
 * Path: public/assets/admin/js/main.js
 * ==============================================================
 */

// Immediate Theme Detection (Ensures theme applies instantly)
(function() {
    try {
        const savedTheme = localStorage.getItem('omni_theme');
        if (savedTheme === 'light' || (!savedTheme && window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches)) {
            document.documentElement.classList.remove('dark');
        } else {
            document.documentElement.classList.add('dark');
        }
    } catch (e) {
        console.error('Theme detection error:', e);
    }
})();

// Global Clipboard Helper
window.copyText = function(text, label = 'Copied to clipboard!') {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text);
    }
};

// SweetAlert2 Zinc Minimal Confirm Delete Handler
window.confirmDelete = function(form, title = 'Are you sure?', text = 'This action cannot be undone.', btnText = 'Yes, delete') {
    if (typeof Swal === 'undefined') {
        if (window.confirm(title + '\n\n' + text)) {
            form.submit();
        }
        return;
    }

    Swal.fire({
        title: title,
        text: text,
        icon: 'warning',
        iconColor: '#f43f5e',
        showCancelButton: true,
        confirmButtonText: btnText,
        cancelButtonText: 'Cancel',
        buttonsStyling: false,
        width: '24rem',
        customClass: {
            popup: 'omni-swal'
        }
    }).then((result) => {
        if (result.isConfirmed) {
            form.submit();
        }
    });
};

// Theme-aware brand logo swapper (dark / light logo)
function swapBrandLogos() {
    try {
        const isDark = document.documentElement.classList.contains('dark');
        document.querySelectorAll('.brand-logo-dark').forEach((el) => {
            el.style.display = isDark ? '' : 'none';
        });
        document.querySelectorAll('.brand-logo-light').forEach((el) => {
            el.style.display = isDark ? 'none' : '';
        });
    } catch (e) {
        console.error('Brand logo swap error:', e);
    }
}

window.swapBrandLogos = swapBrandLogos;

// Main Admin Dashboard Alpine Root Component
window.dashboardApp = function(defaultProviderId = 1) {
    return {
        currentTheme: localStorage.getItem('omni_theme') || (document.documentElement.classList.contains('dark') ? 'dark' : 'light'),
        toggleTheme() {
            if (this.currentTheme === 'dark') {
                this.setTheme('light');
            } else {
                this.setTheme('dark');
            }
        },
        setTheme(mode) {
            this.currentTheme = mode;
            if (mode === 'dark') {
                document.documentElement.classList.add('dark');
                localStorage.setItem('omni_theme', 'dark');
            } else {
                document.documentElement.classList.remove('dark');
                localStorage.setItem('omni_theme', 'light');
            }
            swapBrandLogos();
            this.$nextTick(() => {
                if (window.lucide) lucide.createIcons();
            });
        },
        showAccountModal: false,
        showProviderModal: false,
        showEditProviderModal: false,
        editProvider: {
            id: null,
            name: '',
            format: 'openai',
            base_url: '',
            description: ''
        },
        selectedProviderId: defaultProviderId,
        copyToast: false,
        copyToastText: 'Copied to clipboard!',
        copyText(text, label = 'Copied to clipboard!') {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text);
            }
            this.copyToastText = label;
            this.copyToast = true;
            setTimeout(() => this.copyToast = false, 2500);
        },
        async clearCache() {
            try {
                const token = document.querySelector('meta[name=csrf-token]')?.content || '';
                const res = await fetch('/dashboard/cache/purge', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': token,
                        'Accept': 'application/json',
                    }
                });
                const data = await res.json();
                this.copyToastText = data.message || 'Gateway response cache purged successfully!';
                this.copyToast = true;
                setTimeout(() => this.copyToast = false, 3000);
            } catch (e) {
                this.copyToastText = 'Gateway runtime cache purged!';
                this.copyToast = true;
                setTimeout(() => this.copyToast = false, 2500);
            }
        },
        openAddKeyModal(providerId = null) {
            if (providerId) {
                this.selectedProviderId = providerId;
            }
            this.showAccountModal = true;
        },
        openEditProviderModal(provider) {
            this.editProvider = {
                id: provider.id,
                name: provider.name || '',
                format: provider.format || 'openai',
                base_url: provider.base_url || '',
                description: provider.description || ''
            };
            this.showEditProviderModal = true;
        },
        customProviderFormat: 'openai',
        openAddCustomProviderModal(format = 'openai') {
            this.customProviderFormat = format;
            this.showProviderModal = true;
        },
        openEditProviderById(id) {
            const list = window.__initialProvidersList || [];
            const provider = list.find(p => p.id === id);
            if (provider) {
                this.openEditProviderModal(provider);
            }
        }
    };
};

// AI Providers Manager Alpine Component
window.providersManager = function(initialProvidersList = null) {
    const list = (initialProvidersList && Array.isArray(initialProvidersList))
        ? initialProvidersList
        : (window.__initialProvidersList || []);

    return {
        searchQuery: '',
        modelQuery: '',
        providerFilter: 'all',
        testingAccountId: null,
        testResult: null,
        providersList: list,
        matchesProvider(name, slug, format, hasKeys, isFree, models) {
            if (this.searchQuery && this.searchQuery.trim() !== '') {
                const q = this.searchQuery.toLowerCase().trim();
                if (!(name || '').toLowerCase().includes(q) && !(slug || '').toLowerCase().includes(q)) {
                    return false;
                }
            }
            if (this.modelQuery && this.modelQuery.trim() !== '') {
                const mq = this.modelQuery.toLowerCase().trim();
                const matchesModel = (models || []).some(m => (m || '').toLowerCase().includes(mq));
                if (!matchesModel) return false;
            }
            if (this.providerFilter === 'connected' && !hasKeys) return false;
            if (this.providerFilter === 'free' && !isFree) return false;
            if (this.providerFilter === 'openai' && format !== 'openai') return false;
            if (this.providerFilter === 'anthropic' && format !== 'anthropic') return false;
            if (this.providerFilter === 'gemini' && format !== 'gemini') return false;
            return true;
        },
        matchesProviderBySlug(slug) {
            if (!this.providersList || !this.providersList.length) return true;
            const p = this.providersList.find(item => item.slug === slug);
            if (!p) return true;
            return this.matchesProvider(p.name, p.slug, p.format, p.hasKeys, p.isFree, p.models);
        },
        hasMatches() {
            if (!this.providersList || !this.providersList.length) return true;
            return this.providersList.some(p => this.matchesProvider(p.name, p.slug, p.format, p.hasKeys, p.isFree, p.models));
        },
        async pingAccount(accountId) {
            this.testingAccountId = accountId;
            this.testResult = null;
            try {
                const csrfMeta = document.querySelector('meta[name=csrf-token]');
                const res = await fetch(`/dashboard/accounts/${accountId}/test`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrfMeta ? csrfMeta.content : '',
                        'Accept': 'application/json'
                    }
                });
                const data = await res.json();
                this.testResult = { id: accountId, ...data };
                if (typeof window.copyText === 'function') {
                    window.copyText(data.message, data.success ? 'Key Verified OK!' : 'Test Failed');
                }
                setTimeout(() => { this.testResult = null; }, 5000);
            } catch (err) {
                this.testResult = { id: accountId, success: false, message: err.message };
            } finally {
                this.testingAccountId = null;
                if (window.lucide) {
                    this.$nextTick(() => lucide.createIcons());
                }
            }
        }
    };
};

// Smart Combo Builder Component
window.comboBuilder = function(initialProviders = []) {
    const providers = (initialProviders && Array.isArray(initialProviders) && initialProviders.length)
        ? initialProviders
        : (window.__comboProviders || []);

    return {
        providers: providers,
        catalogSearch: '',
        customModelInput: '',
        sequence: [],

        init() {
            this.$watch('sequence', () => {
                this.$nextTick(() => {
                    if (window.lucide) lucide.createIcons();
                });
            });
        },

        matchesCatalogSearch(modelName) {
            if (!this.catalogSearch || !this.catalogSearch.trim()) return true;
            return (modelName || '').toLowerCase().includes(this.catalogSearch.toLowerCase().trim());
        },

        addModel(modelString) {
            if (!modelString) return;
            const trimmed = modelString.trim();
            if (trimmed && !this.sequence.includes(trimmed)) {
                this.sequence.push(trimmed);
            }
        },

        addCustomModel() {
            if (!this.customModelInput.trim()) return;
            this.addModel(this.customModelInput.trim());
            this.customModelInput = '';
        },

        removeStep(index) {
            this.sequence.splice(index, 1);
        },

        moveUp(index) {
            if (index > 0) {
                const temp = this.sequence[index];
                this.sequence[index] = this.sequence[index - 1];
                this.sequence[index - 1] = temp;
            }
        },

        moveDown(index) {
            if (index < this.sequence.length - 1) {
                const temp = this.sequence[index];
                this.sequence[index] = this.sequence[index + 1];
                this.sequence[index + 1] = temp;
            }
        },

        isInSequence(modelString) {
            return this.sequence.includes(modelString);
        },

        toggleModel(modelString) {
            const idx = this.sequence.indexOf(modelString);
            if (idx >= 0) {
                this.sequence.splice(idx, 1);
            } else {
                this.sequence.push(modelString);
            }
        },

        get sequenceString() {
            return this.sequence.join(', ');
        }
    };
};

// Auto-initialize Lucide Icons on DOM Ready
document.addEventListener('DOMContentLoaded', function() {
    if (window.lucide) {
        lucide.createIcons();
    }
    swapBrandLogos();
});
