// assets/js/recuperar_senha.js - JavaScript para página de recuperação

// Classe para gerenciar recuperação de senha
class RecuperacaoSenha {
    constructor() {
        this.timerInterval = null;
        this.timeLeft = 300; // 5 minutos
        this.formSubmitted = false;
        this.init();
    }
    
    init() {
        this.bindEvents();
        this.setupTimer();
    }
    
    bindEvents() {
        // Formulário de e-mail
        const formEmail = document.getElementById('formEmail');
        if (formEmail) {
            formEmail.addEventListener('submit', (e) => this.handleSubmitEmail(e));
        }
        
        // Formulário de código
        const formCodigo = document.getElementById('formCodigo');
        if (formCodigo) {
            formCodigo.addEventListener('submit', (e) => this.handleSubmitCodigo(e));
        }
        
        // Campos de código
        for (let i = 1; i <= 6; i++) {
            const input = document.getElementById(`code${i}`);
            if (input) {
                input.addEventListener('input', () => this.validateNumber(input));
                input.addEventListener('keyup', (e) => this.moveToNext(e, i));
            }
        }
        
        // Botão reenviar
        const resendLink = document.getElementById('resendLink');
        if (resendLink) {
            resendLink.addEventListener('click', (e) => this.reenviarCodigo(e));
        }
    }
    
    validateNumber(input) {
        input.value = input.value.replace(/[^0-9]/g, '');
    }
    
    moveToNext(event, index) {
        const input = event.target;
        if (input.value.length === 1 && index < 6) {
            document.getElementById(`code${index + 1}`).focus();
        }
        
        // Verificar automaticamente no último campo
        if (index === 6 && input.value.length === 1) {
            this.verificarCodigo();
        }
    }
    
    verificarCodigo() {
        let codigo = '';
        for (let i = 1; i <= 6; i++) {
            const val = document.getElementById(`code${i}`).value;
            if (!val) {
                this.showAlert('Digite todos os dígitos do código', 'warning');
                return;
            }
            codigo += val;
        }
        
        document.getElementById('codigoCompleto').value = codigo;
        document.getElementById('formCodigo').submit();
    }
    
    handleSubmitEmail(event) {
        if (this.formSubmitted) {
            event.preventDefault();
            return false;
        }
        
        const email = document.querySelector('input[name="email"]').value;
        if (!email) {
            event.preventDefault();
            this.showAlert('Digite seu e-mail', 'danger');
            return false;
        }
        
        if (!this.validateEmail(email)) {
            event.preventDefault();
            this.showAlert('Digite um e-mail válido', 'danger');
            return false;
        }
        
        this.formSubmitted = true;
        const button = event.target.querySelector('button');
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Enviando...';
    }
    
    handleSubmitCodigo(event) {
        if (this.formSubmitted) {
            event.preventDefault();
            return false;
        }
        this.formSubmitted = true;
    }
    
    reenviarCodigo(event) {
        event.preventDefault();
        
        if (this.timerInterval) {
            clearInterval(this.timerInterval);
        }
        
        this.timeLeft = 300;
        this.updateTimerDisplay();
        this.startTimer();
        
        const email = document.querySelector('input[name="email"]')?.value || 
                     document.getElementById('resetEmail')?.value;
        
        fetch('recuperar_senha.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `acao=reenviar_codigo&email=${encodeURIComponent(email)}`
        })
        .then(response => response.text())
        .then(html => {
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = html;
            const alertMsg = tempDiv.querySelector('.alert-success');
            if (alertMsg) {
                this.showAlert(alertMsg.innerText, 'success');
            } else {
                this.showAlert('Novo código enviado! Verifique seu e-mail.', 'success');
            }
        })
        .catch(() => {
            this.showAlert('Erro ao reenviar código. Tente novamente.', 'danger');
        });
    }
    
    validateEmail(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    }
    
    showAlert(message, type) {
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type} fade-in`;
        alertDiv.innerHTML = `<i class="fas fa-${type === 'danger' ? 'exclamation-circle' : 'info-circle'} me-2"></i> ${message}`;
        
        const container = document.querySelector('.card-body');
        const firstElement = container.firstChild;
        container.insertBefore(alertDiv, firstElement);
        
        setTimeout(() => {
            alertDiv.remove();
        }, 5000);
    }
    
    setupTimer() {
        if (document.querySelector('.code-input-container')) {
            this.startTimer();
            document.getElementById('code1')?.focus();
        }
    }
    
    startTimer() {
        this.timerInterval = setInterval(() => {
            if (this.timeLeft <= 0) {
                clearInterval(this.timerInterval);
                const timerText = document.getElementById('timerText');
                if (timerText) {
                    timerText.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i> Código expirado. Solicite um novo.';
                }
                // Desabilitar campos de código
                for (let i = 1; i <= 6; i++) {
                    const input = document.getElementById(`code${i}`);
                    if (input) input.disabled = true;
                }
            } else {
                this.timeLeft--;
                this.updateTimerDisplay();
            }
        }, 1000);
    }
    
    updateTimerDisplay() {
        const minutes = Math.floor(this.timeLeft / 60);
        const seconds = this.timeLeft % 60;
        const timer = document.getElementById('timer');
        if (timer) {
            timer.textContent = `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
        }
    }
}

// Inicializar quando o DOM estiver pronto
document.addEventListener('DOMContentLoaded', () => {
    new RecuperacaoSenha();
});