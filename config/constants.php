<?php
// config/constants.php - Constantes globais do sistema

// ============================================
// CONFIGURAÇÕES BÁSICAS DO SISTEMA
// ============================================

// Versão do sistema
define('APP_VERSION', '2.0.0');
define('APP_NAME', 'SIGE SaaS');
define('APP_DESCRIPTION', 'Sistema Integrado de Gestão Escolar - Versão SaaS');
define('APP_AUTHOR', 'SIGE Solutions');

// URLs do sistema
define('APP_URL', 'https://' . $_SERVER['HTTP_HOST']);
define('APP_URL_ADMIN', APP_URL . '/super-admin');
define('APP_URL_SCHOOL', APP_URL . '/escola');
define('APP_URL_API', APP_URL . '/api');

// Caminhos do sistema
define('BASE_PATH', dirname(__DIR__));
define('CONFIG_PATH', BASE_PATH . '/config');
define('LIB_PATH', BASE_PATH . '/lib');
define('ASSETS_PATH', BASE_PATH . '/assets');
define('UPLOADS_PATH', BASE_PATH . '/uploads');
define('LOGS_PATH', BASE_PATH . '/logs');

// URLs dos assets
define('ASSETS_URL', APP_URL . '/assets');
define('CSS_URL', ASSETS_URL . '/css');
define('JS_URL', ASSETS_URL . '/js');
define('IMAGES_URL', ASSETS_URL . '/images');

// ============================================
// CONFIGURAÇÕES DO SISTEMA
// ============================================

// Timezone padrão
define('APP_TIMEZONE', 'Africa/Luanda');
date_default_timezone_set(APP_TIMEZONE);

// Configurações de sessão
define('SESSION_NAME', 'sige_saas_session');
define('SESSION_LIFETIME', 7200); // 2 horas

// Configurações de segurança
define('SALT', 'sige_saas_2026_secure_salt_!@#$%');
define('TOKEN_EXPIRY', 3600); // 1 hora

// ============================================
// CONFIGURAÇÕES DO BANCO DE DADOS
// ============================================

// Estas constantes serão sobrescritas pelo database.php
define('DB_HOST', 'localhost');
define('DB_NAME', 'sige_saas');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// ============================================
// CONFIGURAÇÕES DE UPLOAD
// ============================================

define('MAX_FILE_SIZE', 10485760); // 10MB
define('ALLOWED_EXTENSIONS', 'jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx');
define('UPLOAD_QUALITY', 80);

// ============================================
// CONFIGURAÇÕES DE EMAIL
// ============================================

define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', '');
define('SMTP_PASS', '');
define('SMTP_FROM', 'noreply@sige.com');
define('SMTP_FROM_NAME', APP_NAME);

// ============================================
// CONFIGURAÇÕES DE PAGAMENTO
// ============================================

// Moeda padrão
define('CURRENCY', 'BRL');
define('CURRENCY_SYMBOL', 'R$');
define('CURRENCY_CODE', 'BRL');

// Configurações de pagamento (exemplo com Mercado Pago)
define('MP_ACCESS_TOKEN', '');
define('MP_PUBLIC_KEY', '');
define('MP_INTEGRATOR_ID', '');

// ============================================
// CONFIGURAÇÕES DE PLANOS
// ============================================

define('TRIAL_DAYS', 30); // Dias de trial padrão
define('MAX_SCHOOLS_PER_ADMIN', 100);
define('MAX_USERS_PER_SCHOOL', 1000);

// ============================================
// CONFIGURAÇÕES DE LOG
// ============================================

define('LOG_LEVEL', 'all'); // all, error, warning, info, none
define('LOG_FILE', LOGS_PATH . '/system.log');
define('LOG_ERRORS', true);

// ============================================
// CONFIGURAÇÕES DE CACHE
// ============================================

define('CACHE_ENABLED', false);
define('CACHE_PATH', BASE_PATH . '/cache');
define('CACHE_LIFETIME', 3600);

// ============================================
// MODOS DE OPERAÇÃO
// ============================================

// Modo de desenvolvimento (true = exibe erros, false = produção)
define('DEBUG_MODE', true);

// Modo de manutenção
define('MAINTENANCE_MODE', false);
define('MAINTENANCE_IP_WHITELIST', ['127.0.0.1', '::1']);

// API modo debug
define('API_DEBUG', false);

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

// Função para debug (só exibe se DEBUG_MODE for true)
if (!function_exists('debug')) {
    function debug($data, $die = false) {
        if (DEBUG_MODE) {
            echo '<pre style="background: #f5f5f5; padding: 10px; border: 1px solid #ddd; margin: 10px; border-radius: 5px;">';
            print_r($data);
            echo '</pre>';
            if ($die) die();
        }
    }
}

// Função para log
if (!function_exists('writeLog')) {
    function writeLog($message, $type = 'info') {
        $logFile = LOG_FILE;
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] [{$type}] {$message}" . PHP_EOL;
        
        if (is_writable(LOGS_PATH)) {
            file_put_contents($logFile, $logEntry, FILE_APPEND);
        }
    }
}