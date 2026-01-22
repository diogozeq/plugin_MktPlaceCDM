<?php
/**
 * Autoloader manual para o plugin CDM Catalog Router
 * Usado quando o Composer não está disponível
 *
 * @package CDM_Catalog_Router
 */

declare(strict_types=1);

spl_autoload_register(
	function ( $class ) {
		// Namespace base do plugin
		$prefix = 'CDM\\';
		$base_dir = __DIR__ . '/';

		// Verifica se a classe usa o namespace do plugin
		$len = strlen( $prefix );
		if ( strncmp( $prefix, $class, $len ) !== 0 ) {
			return;
		}

		// Remove o namespace base
		$relative_class = substr( $class, $len );

		// Converte namespace para caminho de arquivo
		$file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

		// Se o arquivo existe, carrega
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);
