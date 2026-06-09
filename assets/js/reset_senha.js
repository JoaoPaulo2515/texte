// assets/js/reset_senha.js - JavaScript para página de redefinição de senha

class ResetSenha {
    constructor() {
        this.novaSenha = document.getElementById('novaSenha');
        this.confirmarSenha = document.getElementById('confirmarSenha');
        this.passwordStrength = document.getElementById('passwordStrength');
        this.confirmError = document.getElementById('confirmError');
        this.submitBtn = document.getElementById('submitBtn');
        this.formSubmitted = false;
        this.init();
    }
    
    init() {
        this.bindEvents();
        this.updateSubmitButton();
    }
    
    bindEvents() {
        if (this.novaSenha) {
            this.novaSenha.addEventListener('input', () => this.checkPasswordStrength());
            this.novaSenha.addEventListener('input', () => this.checkPasswordMatch());
            this.novaSenha.addEventListener('input', () => this.updateSubmitButton());
        }
        
        if (this.confirmarSenha) {
            this.confirmarSenha.addEventListener('input', () => this.checkPasswordMatch());
            this.confirmarSenha.addEventListener('input', () => this.updateSubmitButton());
        }
        
        const form = document.getElementById('formReset');
        if (form) {
            form.addEventListener('submit', (e) => this.handleSubmit(e));
        }
    }
    
    checkPasswordStrength() {
        const password = this.novaSenha.value;
        let strength = 0;
        let message = '';
        let className = '';
        
        if (password.length >= 6) strength++;
        if (password.length >= 10) strength++;
        if (/[A-Z]/.test(password)) strength++;
        if (/[0-9]/.test(password)) strength++;
        if (/[^A-Za-z0-9]/.test(password)) strength++;
        
        if (strength <= 1) {
            message = '🔴 Senha fraca';
            className = 'strength-weak';
        } else if (strength <= 3) {
            message = '🟡 Senha média';
            className = 'strength-medium';
        } else {
            message = '🟢 Senha forte';
            className = 'strength-strong';
        }
        
        if (password.length === 0) {
            message = '';
        }
        
        this.passwordStrength.innerHTML = message;
        this.passwordStrength.className = `password-strength ${className}`;
        
        return strength >= 2;
    }
    
    checkPasswordMatch() {
        const senha = this.novaSenha.value;
        const confirm = this.confirmarSenha.value;
        
        if (confirm.length > 0 && senha !== confirm) {
            this.confirmError.innerHTML = '<i class="fas fa-times-circle me-1"></i> As senhas não coincidem';
            return false;
        } else if (confirm.length > 0 && senha === confirm) {
            this.confirmError.innerHTML = '<i class="fas fa-check-circle me-1"></i> Senhas coincidem';
            this.confirmError.style.color = '#10b981';
            return true;
        } else {
            this.confirmError.innerHTML = '';
            return true;
        }
    }
    
    updateSubmitButton() {
        if (!this.submitBtn) return;
        
        const senhaValida = this.checkPasswordStrength();
        const senhaMatch = this.checkPasswordMatch();
        const hasPassword = this.novaSenha.value.length > 0;
        
        this.submitBtn.disabled = !(senhaValida && senhaMatch && hasPassword);
    }
    
    handleSubmit(event) {
        if (this.formSubmitted) {
            event.preventDefault();
            return false;
        }
        
        const senha = this.novaSenha.value;
        const confirm = this.confirmarSenha.value;
        
        if (senha.length < 6) {
            event.preventDefault();
            this.showError('A senha deve ter no mínimo 6 caracteres');
            return false;
        }
        
        if (senha !== confirm) {
            event.preventDefault();
            this.showError('As senhas não coincidem');
            return false;
        }
        
        this.formSubmitted = true;
        this.submitBtn.disabled = true;
        this.submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Alterando senha...';
    }
    
    showError(message) {
        const errorDiv = document.createElement('div');
        errorDiv.className = 'alert alert-danger fade-in';
        errorDiv.innerHTML = `<i class="fas fa-exclamation-circle me-2"></i> ${message}`;
        
        const form = document.getElementById('formReset');
        form.insertBefore(errorDiv, form.firstChild);
        
        setTimeout(() => {
            errorDiv.remove();
        }, 5000);
    }
}

// Inicializar quando o DOM estiver pronto
document.addEventListener('DOMContentLoaded', () => {
    new ResetSenha();
});