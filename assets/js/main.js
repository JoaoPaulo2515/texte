/**
 * SIGE ANGOLA - JavaScript Offline First
 * Versão: 1.0
 * Funciona completamente sem conexão com internet
 */

// ============================================
// DOM CONTENT LOADED
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    
    // Inicializar todas as funcionalidades
    initMobileMenu();
    initSmoothScroll();
    initBackToTop();
    initAnimations();
    initCounterAnimation();
    initNewsletterForm();
    initGalleryFilter();
    initOfflineDetection();
    initLocalStorage();
    initFormValidation();
    
});

// ============================================
// MOBILE MENU TOGGLE
// ============================================
function initMobileMenu() {
    const menuToggle = document.querySelector('.menu-toggle');
    const navMenu = document.querySelector('.nav-menu');
    
    if (menuToggle && navMenu) {
        menuToggle.addEventListener('click', function() {
            navMenu.classList.toggle('active');
            
            // Mudar ícone do menu
            const icon = menuToggle.querySelector('i, .icon');
            if (icon) {
                if (navMenu.classList.contains('active')) {
                    icon.innerHTML = '✕';
                } else {
                    icon.innerHTML = '☰';
                }
            }
        });
    }
    
    // Fechar menu ao clicar em link
    const navLinks = document.querySelectorAll('.nav-link');
    navLinks.forEach(link => {
        link.addEventListener('click', () => {
            if (navMenu) navMenu.classList.remove('active');
        });
    });
}

// ============================================
// SMOOTH SCROLL
// ============================================
function initSmoothScroll() {
    const links = document.querySelectorAll('a[href^="#"]');
    
    links.forEach(link => {
        link.addEventListener('click', function(e) {
            const href = this.getAttribute('href');
            if (href === '#' || href === '#0') return;
            
            const target = document.querySelector(href);
            if (target) {
                e.preventDefault();
                const offsetTop = target.offsetTop - 80;
                window.scrollTo({
                    top: offsetTop,
                    behavior: 'smooth'
                });
            }
        });
    });
}

// ============================================
// BACK TO TOP BUTTON
// ============================================
function initBackToTop() {
    const btn = document.createElement('button');
    btn.className = 'back-to-top';
    btn.innerHTML = '↑';
    btn.setAttribute('aria-label', 'Voltar ao topo');
    document.body.appendChild(btn);
    
    window.addEventListener('scroll', function() {
        if (window.scrollY > 300) {
            btn.classList.add('show');
        } else {
            btn.classList.remove('show');
        }
    });
    
    btn.addEventListener('click', function() {
        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    });
}

// ============================================
// ANIMAÇÕES AO SCROLL
// ============================================
function initAnimations() {
    const animatedElements = document.querySelectorAll('.feature-card, .stat-card, .pricing-card, .gallery-item');
    
    // Adicionar classes de animação
    animatedElements.forEach((el, index) => {
        el.classList.add('fade-in-up');
        el.style.animationDelay = (index * 0.1) + 's';
    });
    
    // Observer para animações ao scroll
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.1 });
    
    document.querySelectorAll('.fade-in-up, .slide-left, .slide-right').forEach(el => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(30px)';
        observer.observe(el);
    });
}

// ============================================
// CONTADOR ANIMADO
// ============================================
function initCounterAnimation() {
    const counters = document.querySelectorAll('.stat-number');
    
    const animateCounter = (counter) => {
        let target = parseInt(counter.getAttribute('data-target'));
        if (isNaN(target)) {
            target = parseInt(counter.innerText.replace(/[^0-9]/g, ''));
        }
        if (isNaN(target)) return;
        
        let current = 0;
        const increment = target / 50;
        const updateCounter = () => {
            current += increment;
            if (current < target) {
                counter.innerText = Math.floor(current).toLocaleString();
                requestAnimationFrame(updateCounter);
            } else {
                counter.innerText = target.toLocaleString();
            }
        };
        updateCounter();
    };
    
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                animateCounter(entry.target);
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.5 });
    
    counters.forEach(counter => {
        const value = counter.innerText.replace(/[^0-9]/g, '');
        counter.setAttribute('data-target', value);
        counter.innerText = '0';
        observer.observe(counter);
    });
}

// ============================================
// NEWSLETTER FORM (Offline First)
// ============================================
function initNewsletterForm() {
    const form = document.querySelector('.newsletter-form');
    
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const emailInput = this.querySelector('input[type="email"]');
            if (!emailInput) return;
            
            const email = emailInput.value;
            
            if (validateEmail(email)) {
                // Salvar no localStorage
                const subscriptions = JSON.parse(localStorage.getItem('newsletter_subscriptions') || '[]');
                if (!subscriptions.includes(email)) {
                    subscriptions.push(email);
                    localStorage.setItem('newsletter_subscriptions', JSON.stringify(subscriptions));
                }
                
                showToast('Inscrição realizada com sucesso!', 'success');
                this.reset();
            } else {
                showToast('Por favor, insira um e-mail válido.', 'error');
            }
        });
    }
}

// ============================================
// GALERIA FILTER (CORRIGIDO)
// ============================================
function initGalleryFilter() {
    const filterButtons = document.querySelectorAll('.filter-btn');
    const galleryItems = document.querySelectorAll('.gallery-item');
    
    if (filterButtons.length > 0 && galleryItems.length > 0) {
        filterButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                const filter = this.getAttribute('data-filter');
                
                filterButtons.forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                
                galleryItems.forEach(item => {
                    if (filter === 'all' || item.getAttribute('data-category') === filter) {
                        item.style.display = 'block';
                        setTimeout(() => {
                            item.style.opacity = '1';
                            item.style.transform = 'scale(1)';
                        }, 50);
                    } else {
                        item.style.opacity = '0';
                        item.style.transform = 'scale(0.8)';
                        setTimeout(() => {
                            item.style.display = 'none';
                        }, 300);
                    }
                });
            });
        });
    }
}

// ============================================
// OFFLINE DETECTION
// ============================================
function initOfflineDetection() {
    window.addEventListener('online', () => {
        showToast('Conexão restaurada!', 'success');
    });
    
    window.addEventListener('offline', () => {
        showToast('Sem conexão com internet. Usando dados locais.', 'warning');
    });
    
    // Verificar status inicial
    if (!navigator.onLine) {
        setTimeout(() => {
            showToast('Modo offline ativo. Alguns recursos podem estar limitados.', 'info');
        }, 1000);
    }
}

// ============================================
// LOCAL STORAGE MANAGER
// ============================================
function initLocalStorage() {
    // Salvar preferências do usuário
    const saveUserPreference = (key, value) => {
        try {
            localStorage.setItem(`sige_${key}`, JSON.stringify(value));
        } catch(e) {
            console.warn('Erro ao salvar no localStorage:', e);
        }
    };
    
    const getUserPreference = (key) => {
        try {
            const data = localStorage.getItem(`sige_${key}`);
            return data ? JSON.parse(data) : null;
        } catch(e) {
            console.warn('Erro ao ler do localStorage:', e);
            return null;
        }
    };
    
    // Salvar tema
    const saveTheme = (theme) => {
        localStorage.setItem('sige_theme', theme);
        document.body.setAttribute('data-theme', theme);
    };
    
    // Carregar tema salvo
    const savedTheme = localStorage.getItem('sige_theme');
    if (savedTheme) {
        document.body.setAttribute('data-theme', savedTheme);
    }
    
    // Cache de dados
    window.cacheData = (key, data, expiryHours = 24) => {
        try {
            const item = {
                data: data,
                expiry: Date.now() + (expiryHours * 60 * 60 * 1000)
            };
            localStorage.setItem(`cache_${key}`, JSON.stringify(item));
        } catch(e) {
            console.warn('Erro ao salvar cache:', e);
        }
    };
    
    window.getCachedData = (key) => {
        try {
            const item = localStorage.getItem(`cache_${key}`);
            if (!item) return null;
            
            const parsed = JSON.parse(item);
            if (Date.now() > parsed.expiry) {
                localStorage.removeItem(`cache_${key}`);
                return null;
            }
            
            return parsed.data;
        } catch(e) {
            console.warn('Erro ao ler cache:', e);
            return null;
        }
    };
}

// ============================================
// FORM VALIDATION
// ============================================
function initFormValidation() {
    const forms = document.querySelectorAll('form');
    
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            let isValid = true;
            const inputs = this.querySelectorAll('input[required], textarea[required], select[required]');
            
            inputs.forEach(input => {
                if (!input.value.trim()) {
                    isValid = false;
                    showInputError(input, 'Este campo é obrigatório.');
                } else if (input.type === 'email' && !validateEmail(input.value)) {
                    isValid = false;
                    showInputError(input, 'Insira um e-mail válido.');
                } else if (input.type === 'password' && input.value.length < 6) {
                    isValid = false;
                    showInputError(input, 'A senha deve ter no mínimo 6 caracteres.');
                } else {
                    clearInputError(input);
                }
            });
            
            if (!isValid) {
                e.preventDefault();
            }
        });
    });
}

// ============================================
// HELPER FUNCTIONS
// ============================================

// Validar e-mail
function validateEmail(email) {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
}

// Mostrar erro no input
function showInputError(input, message) {
    input.classList.add('error');
    let errorDiv = input.parentElement.querySelector('.error-message');
    if (!errorDiv) {
        errorDiv = document.createElement('small');
        errorDiv.className = 'error-message';
        errorDiv.style.color = '#dc3545';
        errorDiv.style.fontSize = '0.75rem';
        errorDiv.style.marginTop = '5px';
        errorDiv.style.display = 'block';
        input.parentElement.appendChild(errorDiv);
    }
    errorDiv.textContent = message;
}

// Limpar erro do input
function clearInputError(input) {
    input.classList.remove('error');
    const errorDiv = input.parentElement.querySelector('.error-message');
    if (errorDiv) {
        errorDiv.remove();
    }
}

// Mostrar toast notification
function showToast(message, type = 'info') {
    // Remover toasts existentes
    const existingToasts = document.querySelectorAll('.toast-notification');
    existingToasts.forEach(toast => toast.remove());
    
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type}`;
    
    let icon = '';
    switch(type) {
        case 'success': icon = '✅'; break;
        case 'error': icon = '❌'; break;
        case 'warning': icon = '⚠️'; break;
        default: icon = 'ℹ️';
    }
    
    toast.innerHTML = `
        <span style="font-size: 1.2rem;">${icon}</span>
        <span>${message}</span>
    `;
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(100%)';
        setTimeout(() => {
            if (toast.parentNode) toast.remove();
        }, 300);
    }, 3000);
}

// Scroll para elemento
function scrollToElement(element, offset = 80) {
    if (typeof element === 'string') {
        element = document.querySelector(element);
    }
    if (element) {
        const offsetTop = element.offsetTop - offset;
        window.scrollTo({
            top: offsetTop,
            behavior: 'smooth'
        });
    }
}

// Debounce para otimização
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Lazy loading de imagens
function initLazyLoading() {
    const images = document.querySelectorAll('img[data-src]');
    
    const imageObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const img = entry.target;
                img.src = img.dataset.src;
                img.removeAttribute('data-src');
                imageObserver.unobserve(img);
            }
        });
    });
    
    images.forEach(img => imageObserver.observe(img));
}

// Exportar funções para uso global
window.SIGE = {
    showToast: showToast,
    scrollToElement: scrollToElement,
    validateEmail: validateEmail,
    debounce: debounce,
    initLazyLoading: initLazyLoading,
    cacheData: function(key, data, expiryHours) {
        if (window.cacheData) window.cacheData(key, data, expiryHours);
    },
    getCachedData: function(key) {
        if (window.getCachedData) return window.getCachedData(key);
        return null;
    }
};

// ============================================
// SERVICE WORKER (Opcional - para PWA)
// ============================================
if ('serviceWorker' in navigator && (window.location.protocol === 'https:' || window.location.hostname === 'localhost')) {
    window.addEventListener('load', function() {
        navigator.serviceWorker.register('/sw.js').then(function(registration) {
            console.log('ServiceWorker registration successful with scope: ', registration.scope);
        }).catch(function(err) {
            console.log('ServiceWorker registration failed: ', err);
        });
    });
}

// ============================================
// PWA INSTALL PROMPT
// ============================================
let deferredPrompt;
window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    
    // Mostrar botão de instalação
    const installBtn = document.querySelector('.install-app-btn');
    if (installBtn) {
        installBtn.style.display = 'flex';
        installBtn.addEventListener('click', () => {
            if (deferredPrompt) {
                deferredPrompt.prompt();
                deferredPrompt.userChoice.then((choiceResult) => {
                    if (choiceResult.outcome === 'accepted') {
                        console.log('User accepted the install prompt');
                    }
                    deferredPrompt = null;
                });
            }
        });
    }
});