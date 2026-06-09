<?php
// lib/Autoloader.php - Carregamento automático de classes
/**
 * Classe Autoloader para carregamento automático de classes
 * @package SIGE Angola
 * @version 1.0
 */

class Autoloader
{
    /**
     * Registra o autoloader no PHP
     */
    public static function register()
    {
        spl_autoload_register([__CLASS__, 'load']);
    }

    /**
     * Carrega a classe solicitada
     * @param string $className Nome da classe a ser carregada
     */
    public static function load($className)
    {
        // Mapeamento de classes para arquivos
        $classMap = [
            'Database' => __DIR__ . '/Database.php',
            'Auth' => __DIR__ . '/Auth.php',
            'Pagamento' => __DIR__ . '/Pagamento.php',
            'Email' => __DIR__ . '/Email.php',
            'Validator' => __DIR__ . '/Validator.php',
            'Upload' => __DIR__ . '/Upload.php',
            'Report' => __DIR__ . '/Report.php',
            'API' => __DIR__ . '/API.php',
            'Cache' => __DIR__ . '/Cache.php',
            'Log' => __DIR__ . '/Log.php'
        ];

        // Verificar se a classe está mapeada
        if (isset($classMap[$className])) {
            require_once $classMap[$className];
            return;
        }

        // Tentar carregar de diretórios específicos
        $directories = [
            __DIR__ . '/',
            __DIR__ . '/../config/',
            __DIR__ . '/../models/',
            __DIR__ . '/../controllers/'
        ];

        foreach ($directories as $directory) {
            $file = $directory . $className . '.php';
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }
    }
}