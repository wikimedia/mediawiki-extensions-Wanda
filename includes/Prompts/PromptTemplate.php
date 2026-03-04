<?php

namespace MediaWiki\Extension\Wanda\Prompts;

/**
 * Loads and renders prompt templates from the templates/ directory.
 *
 * Templates are plain text files with {{placeholder}} tokens that are
 * substituted at render time. Keeping prompts in files (rather than
 * inline PHP strings) separates prompt engineering from code and allows
 * changes without a PHP deployment.
 */
class PromptTemplate {

	private static function templatesDir(): string {
		return __DIR__ . '/templates';
	}

	/**
	 * Load a named template, substitute {{key}} placeholders, and return the result.
	 *
	 * @param string $name Template name without the .txt extension
	 * @param array $vars Associative array of placeholder → value substitutions
	 * @return string
	 * @throws \RuntimeException if the template file does not exist
	 */
	public static function render( string $name, array $vars = [] ): string {
		$path = self::templatesDir() . '/' . $name . '.txt';
		$template = file_get_contents( $path );
		if ( $template === false ) {
			throw new \RuntimeException( "Prompt template not found: $name" );
		}
		foreach ( $vars as $key => $value ) {
			$template = str_replace( '{{' . $key . '}}', (string)$value, $template );
		}
		return $template;
	}
}
