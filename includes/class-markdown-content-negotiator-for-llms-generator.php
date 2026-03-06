<?php
/**
 * Generator for AI Markdown.
 *
 * @package Markdown_Content_Negotiator_For_LLMs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Markdown_Content_Negotiator_For_LLMs_Generator
 *
 * Converts WordPress posts to Markdown with YAML frontmatter.
 */
class Markdown_Content_Negotiator_For_LLMs_Generator {

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Nothing to initialize here for now.
	}

	/**
	 * Convert post content and metadata to Markdown with YAML frontmatter.
	 *
	 * @param  WP_Post $post The post object.
	 * @return string The Markdown content.
	 */
	public function generate_markdown( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return '';
		}

		// Handle WooCommerce Products specifically.
		if ( 'product' === $post->post_type && class_exists( 'WooCommerce' ) ) {
			return $this->generate_product_markdown( $post );
		}

		$frontmatter = array(
			'title'      => $post->post_title,
			'date'       => $post->post_date,
			'author'     => get_the_author_meta( 'display_name', $post->post_author ),
			'permalink'  => get_permalink( $post->ID ),
			'categories' => wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) ),
			'tags'       => wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) ),
		);

		$thumbnail_url = '';
		$thumbnail_alt = '';
		if ( function_exists( 'get_post_thumbnail_id' ) ) {
			$thumb_id = get_post_thumbnail_id( $post->ID );
			if ( $thumb_id ) {
				if ( function_exists( 'wp_get_attachment_url' ) ) {
					$thumbnail_url = wp_get_attachment_url( $thumb_id );
				} elseif ( function_exists( 'get_the_post_thumbnail_url' ) ) {
					$thumbnail_url = get_the_post_thumbnail_url( $post->ID, 'full' );
				}
				if ( function_exists( 'get_post_meta' ) ) {
					$thumbnail_alt = get_post_meta( $thumb_id, '_wp_attachment_image_alt', true );
				}
			}
		}

		if ( $thumbnail_url ) {
			$frontmatter['featured_image']     = $thumbnail_url;
			$frontmatter['featured_image_alt'] = $thumbnail_alt;
		}

		$description = '';
		if ( function_exists( 'get_the_excerpt' ) ) {
			$description = trim( (string) get_the_excerpt( $post ) );
		}
		if ( empty( $description ) ) {
			$text = '';
			if ( ! empty( $post->post_excerpt ) ) {
				$text = $post->post_excerpt;
			} else {
				$text = $post->post_content;
			}
			$text  = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5 );
			$clean = wp_strip_all_tags( $text );
			$clean = preg_replace( '/\s+/u', ' ', $clean );
			$clean = trim( $clean );
			if ( mb_strlen( $clean ) > 160 ) {
				$description = rtrim( mb_substr( $clean, 0, 157 ) ) . '...';
			} else {
				$description = $clean;
			}
		}

		if ( '' !== $description ) {
			$frontmatter['description'] = $description;
		}

		$markdown = "---\n";
		foreach ( $frontmatter as $key => $value ) {
			if ( is_array( $value ) ) {
				$markdown .= "$key: [" . implode( ', ', array_map( array( $this, 'quote' ), $value ) ) . "]\n";
			} else {
				$markdown .= "$key: " . $this->quote( $value ) . "\n";
			}
		}
		$markdown .= "---\n\n";

		if ( ! empty( $frontmatter['featured_image'] ) ) {
			$alt       = isset( $frontmatter['featured_image_alt'] ) ? $frontmatter['featured_image_alt'] : '';
			$markdown .= '![' . $this->quote( $alt ) . '](' . $frontmatter['featured_image'] . ")\n\n";
		}

		$content = $post->post_content;
		if ( has_blocks( $content ) ) {
			$markdown .= $this->convert_blocks_to_markdown( $content );
		} else {
			$markdown .= $this->convert_html_to_markdown( $content );
		}

		return trim( $markdown );
	}

	/**
	 * Basic block-to-markdown conversion.
	 *
	 * @param string $content The block content.
	 * @return string Markdown output.
	 */
	private function convert_blocks_to_markdown( $content ) {
		$blocks = parse_blocks( $content );
		$output = '';

		foreach ( $blocks as $block ) {
			if ( ! empty( $block['blockName'] ) ) {
				switch ( $block['blockName'] ) {
					case 'core/paragraph':
						$output .= wp_strip_all_tags( $block['innerHTML'] ) . "\n\n";
						break;
					case 'core/heading':
						$level   = isset( $block['attrs']['level'] ) ? $block['attrs']['level'] : 2;
						$output .= str_repeat( '#', $level ) . ' ' . wp_strip_all_tags( $block['innerHTML'] ) . "\n\n";
						break;
					case 'core/list':
						$output .= $this->convert_html_to_markdown( $block['innerHTML'] ) . "\n";
						break;
					case 'core/image':
						$url     = isset( $block['attrs']['url'] ) ? $block['attrs']['url'] : '';
						$alt     = isset( $block['attrs']['alt'] ) ? $block['attrs']['alt'] : '';
						$output .= "![$alt]($url)\n\n";
						break;
					case 'core/code':
						$output .= "```\n" . wp_strip_all_tags( $block['innerHTML'] ) . "\n```\n\n";
						break;
					default:
						$output .= wp_strip_all_tags( render_block( $block ) ) . "\n\n";
						break;
				}
			}
		}

		return $output;
	}

	/**
	 * Simple HTML to Markdown fallback.
	 *
	 * @param string $html The HTML content to convert.
	 * @return string Markdown output.
	 */
	private function convert_html_to_markdown( $html ) {
		$markdown = $html;

		$code_blocks = array();
		$markdown    = preg_replace_callback(
			'/```([^\n\r]*)\n(.*?)\n```/s',
			function ( $m ) use ( &$code_blocks ) {
				$i                            = count( $code_blocks );
				$code_blocks[ "__CB_{$i}__" ] = '```' . $m[1] . "\n" . $m[2] . "\n```";
				return "__CB_{$i}__";
			},
			$markdown
		);

		$markdown = preg_replace_callback(
			'#<pre(?P<pre_attrs>[^>]*)>\s*(?:<code(?P<code_attrs>[^>]*)>)?(?P<code>.*?)(?:</code>)?\s*</pre>#is',
			function ( $m ) use ( &$code_blocks ) {
				$code  = html_entity_decode( $m['code'] );
				$attrs = $m['code_attrs'] . ' ' . $m['pre_attrs'];
				$lang  = '';
				if ( preg_match( '/class=["\']([^"\']+)["\']/', $attrs, $cm ) ) {
					if ( preg_match( '/(?:language-|lang-)([a-z0-9+-]+)/i', $cm[1], $lm ) ) {
						$lang = strtolower( $lm[1] );
					}
				}

				$code = rtrim( $code, "\n\r" );
				preg_match_all( '/`+/', $code, $bt_matches );
				$max_ticks = 0;
				if ( ! empty( $bt_matches[0] ) ) {
					foreach ( $bt_matches[0] as $run ) {
						$len = strlen( $run );
						if ( $len > $max_ticks ) {
							$max_ticks = $len;
						}
					}
				}
				$fence = str_repeat( '`', max( 3, $max_ticks + 1 ) );
				if ( $lang ) {
					$block = $fence . $lang . "\n" . $code . "\n" . $fence . "\n\n";
				} else {
					$block = $fence . "\n" . $code . "\n" . $fence . "\n\n";
				}
				$i                           = count( $code_blocks );
				$placeholder                 = "__CB_{$i}__";
				$code_blocks[ $placeholder ] = $block;
				return $placeholder;
			},
			$markdown
		);

		$markdown = preg_replace_callback(
			'#<code(?P<attrs>[^>]*)>(?P<code>.*?)</code>#is',
			function ( $m ) {
				$code = html_entity_decode( $m['code'] );
				if ( false !== strpos( $code, '`' ) ) {
					return '``' . $code . '``';
				}
				return '`' . $code . '`';
			},
			$markdown
		);

		$markdown = preg_replace( '/<h([1-6])>(.*?)<\/h\1>/i', "\n" . str_repeat( '#', (int) '\\1' ) . ' $2' . "\n", $markdown );

		$markdown = preg_replace( '/<a[^>]*href=["\'](.*?)["\'][^>]*>(.*?)<\/a>/i', '[$2]($1)', $markdown );

		$markdown = preg_replace( '/<(strong|b)>(.*?)<\/\1>/i', '**$2**', $markdown );
		$markdown = preg_replace( '/<(em|i)>(.*?)<\/\1>/i', '*$2*', $markdown );

		$markdown = preg_replace( '/<li>(.*?)<\/li>/i', "- $1\n", $markdown );
		$markdown = preg_replace( '/<(ul|ol)>|<\/\1>/i', '', $markdown );

		$markdown = wp_strip_all_tags( $markdown );

		$markdown = html_entity_decode( $markdown, ENT_QUOTES | ENT_HTML5 );

		if ( ! empty( $code_blocks ) ) {
			$markdown = str_replace( array_keys( $code_blocks ), array_values( $code_blocks ), $markdown );
		}

		return $markdown;
	}

	/**
	 * Generate Markdown for a WooCommerce product using the template.
	 *
	 * @param WP_Post $post The product post object.
	 * @return string The Markdown content.
	 */
	private function generate_product_markdown( $post ) {
		$product = wc_get_product( $post->ID );
		if ( ! $product ) {
			return '';
		}

		$template_path = plugin_dir_path( __DIR__ ) . 'markdown-templates/product-template.md';
		if ( ! file_exists( $template_path ) ) {
			return '';
		}

		$template = file_get_contents( $template_path );

		// Prepare data for replacement.
		$product_name        = $product->get_name();
		$description         = $product->get_description();
		$short_description   = $product->get_short_description();
		$price               = $product->get_price();
		$currency            = get_woocommerce_currency_symbol();
		$availability        = $product->is_in_stock() ? 'In Stock' : 'Out of Stock';
		$permalink           = get_permalink( $post->ID );
		$thumbnail_id        = $product->get_image_id();
		$thumbnail_url       = $thumbnail_id ? wp_get_attachment_url( $thumbnail_id ) : '';
		$thumbnail_alt       = $thumbnail_id ? get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ) : '';

		// Features (could be attributes or from the main description).
		$features_output = '';
		$attributes      = $product->get_attributes();
		if ( ! empty( $attributes ) ) {
			foreach ( $attributes as $attribute ) {
				$name = wc_attribute_label( $attribute->get_name() );
				if ( $attribute->is_taxonomy() ) {
					$terms = $attribute->get_terms();
					$values = array();
					foreach ( $terms as $term ) {
						$values[] = $term->name;
					}
					$value = implode( ', ', $values );
				} else {
					$value = $attribute->get_options()[0];
				}
				$features_output .= "*   **$name:** $value\n";
			}
		}

		// Specifications table.
		$specs_table  = "| Specification | Detail |\n";
		$specs_table .= "| :--- | :--- |\n";
		$specs_table .= "| **Dimensions** | " . $product->get_dimensions( false ) . " |\n";
		$specs_table .= "| **Weight** | " . $product->get_weight() . " " . get_option( 'woocommerce_weight_unit' ) . " |\n";
		$specs_table .= "| **SKU** | " . $product->get_sku() . " |\n";

		// Combine content.
		$replacements = array(
			'[Product Name]'                       => $product_name,
			'A brief, compelling overview of the product. Focus on its purpose and primary benefit to the user. This section should be no more than a couple of paragraphs.' => $short_description ? wp_strip_all_tags( $short_description ) : wp_strip_all_tags( $description ),
			"*   **Feature 1:** A short explanation of what this feature does.\n*   **Feature 2:** Detail the benefit of the feature.\n*   **Feature 3:** Mention any specific technical aspect or user value." => trim( $features_output ) ?: 'No specific features listed.',
			"| Specification | Detail |\n| :--- | :--- |\n| **Dimensions** | X\" x Y\" x Z\" |\n| **Weight** | W lbs |\n| **Material** | Type of material |\n| **Color(s)** | Available colors |" => trim( $specs_table ),
			'$XX.XX'                               => $price . ' ' . $currency,
			'[In Stock / Out of Stock]'            => $availability,
			'https://example.com'                  => $permalink,
			'![Product Image Alt Text](https://example.com)' => "![$thumbnail_alt]($thumbnail_url)",
		);

		foreach ( $replacements as $placeholder => $value ) {
			$template = str_replace( $placeholder, $value, $template );
		}

		return trim( $template );
	}

	/**
	 * Estimate token count based on heuristic.
	 *
	 * @param string $content The markdown content.
	 * @return int Token count.
	 */
	public static function estimate_markdown_tokens( $content ) {
		$char_count = mb_strlen( $content );
		return ceil( $char_count / 4 );
	}

	/**
	 * Helper to quote YAML strings.
	 *
	 * @param string $str The string to quote.
	 * @return string Quoted string.
	 */
	private function quote( $str ) {
		return '"' . str_replace( '"', '\\"', $str ) . '"';
	}
}
