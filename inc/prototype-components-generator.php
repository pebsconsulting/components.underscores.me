<?php
/**
 * Plugin Name: Components Generator
 * Description: Generates themes based on Components by Automattic.
 */

class Components_Generator_Plugin {

	var $build_dir = 'build/';
	var $repo_url = 'https://codeload.github.com/Automattic/theme-components/zip/master';
	var $repo_file_name = 'theme-components-master.zip';
	var $components_dir;
	var $bypass_cache = false;
	var $logging = true;

	function __construct() {
		// Initialize class properties.
		$this->bypass_cache = apply_filters( 'components_bypass_cache', false );
		$this->logging = apply_filters( 'components_logging', true );
		$this->build_dir = sprintf( '%s/%s', get_stylesheet_directory(), $this->build_dir );
		$this->repo_url = esc_url_raw( $this->repo_url );
		$this->components_dir = $this->build_dir . str_replace( '.zip', '', $this->repo_file_name );

		// Patch repo url and filename to work with `branchless-merge` branch
		// TODO: remove this code after the branch is merged.
		$this->repo_url = preg_replace( '%/master$%', '/branchless-merge', $this->repo_url );
		$this->repo_file_name = preg_replace( '%-master.zip$%', '-branchless-merge.zip', $this->repo_file_name );
		$this->components_dir = preg_replace( '%-master$%', '-branchless-merge', $this->components_dir );

		// Let's run a few init functions to set things up.
		add_action( 'init', array( $this, 'set_expiration_and_go' ) );

		// Use do_action( 'render_types_form' ); in your theme to render the form.
		add_action( 'render_types_form', array( $this, 'render_types_form' ) );
	}

	/**
	 * This is an init function to grab theme components so we can control when it's called by the generator.
	 */
	public function get_theme_components_init() {
		// Grab theme components from its Github repo.
		$this->get_theme_components( $this->build_dir );

		// Generate our types cache.
		$this->gen_types_cache();
	}

	/**
	 * Creates an array and a JSON file from type configs to cache type data.
	 * The generator form uses this to render type choices automatically.
	 */
	public function gen_types_cache() {
		// Our array of data that we use to cache the types.
		$types = array();

		// Scan our config files and grab the file names.
		$configs = $this->components_dir . '/configs/*.json';
		$files = glob( $configs );

		// Check for valid config files, pull out the type names, capitalize them, and use them for data.
		if ( is_array( $files ) && ! empty( $files ) ) {
			foreach( $files as $file ) {
				preg_match( '%/type-([^\.]+)\.json$%', $file, $matches );
				if ( ! empty( $matches ) ) {
					$id = $matches[1];
					$title = join( ' ', array_map( 'ucwords', explode( '-', $matches[1] ) ) );
					$types[$id] = $title;
				}
			}
			// Create a JSON file from our $types array so that we can use it for as a cache for rendering the generator form.
			file_put_contents( $this->build_dir . 'types.json', json_encode( $types, JSON_PRETTY_PRINT ) );
		} else {
			$this->log_message( __( 'Error: type.json was not rebuilt successfully because configs were not able to be read.' ) );
		}

	}

	/**
	 * Places data in JSON files in an array for later use.
	 */
	public function read_json( $file ) {
		$json = file_get_contents( $file );
		return json_decode( $json, TRUE );
	}

	/**
	 * Builds a given type from theme components.
	 */
	public function build_type( $type ) {
		// The target directory where we will be working on.
		$target_dir = $this->build_dir . $type;

		// Get type config.
		$config_path = sprintf( '%s/configs/type-%s.json', $this->components_dir, $type );
		$config = $this->read_json( $config_path );

		// Copy just build files we need to start with so we can work with them.
		$exclude_from_build = array( 'assets', 'components', 'configs', 'CONTRIBUTING.md', 'README.md', 'templates', 'types' );
		$this->copy_build_files( $this->components_dir, $target_dir, $exclude_from_build );

		// Handle config.
		$this->handle_config( $type, $config, $target_dir );
	}

	/**
	 * This gets our zip from the Github repo.
	 */
	public function get_theme_components( $destination ) {
		// Make sure the build dir exists.
		$this->ensure_directory( $this->build_dir );

		// Get our download.
		$this->download_file( $this->repo_url, $this->repo_file_name );

		// Copy the file to its new directory.
		copy( ABSPATH . $this->repo_file_name, sprintf( '%s/%s', $destination, $this->repo_file_name ) );

		// Unzip the file.
		$this->unzip_file( sprintf( '%s/%s', $destination, $this->repo_file_name ) );

		// Delete the unneeded files. Original download in root.
		$this->delete_file( ABSPATH . $this->repo_file_name );
	}

	/**
	 * Read files to process from base. Stores files on array for processing.
	 */
	public function read_base_dir( $dir ) {
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir ) );
		$filelist = array();
		$exclude = array( '.travis.yml', 'codesniffer.ruleset.xml', 'README.md', 'CONTRIBUTING.md', '.git', '.svn', '.DS_Store', '.gitignore', '.', '..' );
		foreach( $files as $file ) {
			if ( ! in_array( basename( $file ), $exclude )  ) {
				$filelist[] = $file;
			}
		}
		return $filelist;
	}

	/**
	 * Handles the configuration and coordinates everything.
	 */
	public function handle_config( $type, $config, $target_dir ) {
		foreach ( $config as $section => $args ) {
			switch ( $section ) {
				case 'replacement_files';
					$this->add_replacement_files( $type, $args, $target_dir );
					break;
				case 'sass_replace';
					$this->add_sass_includes( $type, $args, $target_dir );
					break;
				case 'components';
					if ( isset( $config['replacement_files'] ) ) {
						$replacements = (array) $config['replacement_files'];
					} else {
						$replacements = array();
					}
					$this->add_component_files( $args, $target_dir, $replacements );
					break;
				case 'templates';
					$this->add_templates( $args, $target_dir );
					break;
				case 'js';
					$this->add_javascript( $args, $target_dir );
					break;
			}
		}
	}

	/**
	 * Adds component files needed for the build.
	 */
	public function add_component_files( $components, $target_dir, $replacements ) {
		 // Ensure components directory exists.
		 $this->ensure_directory( $target_dir . '/components' );

		 // Insert array, used to insert components.
		 $insert = array();

		// Iterate over each component.
		foreach ( $components as $comp ) {
			// Check if PHP extension has been specified.
			$is_phpfile = preg_match( '/\\.php$/', $comp );

			// Get the component path.
			$path = sprintf( '%s/components/%s', $this->components_dir, $comp );

			// If extension is not specified, check if file exists with extension.
			if ( ! $is_phpfile && file_exists( $path . '.php' ) ) {
				$phpfile_exists = true;
				$is_phpfile = true;
				$path .= '.php';
			} else {
				$phpfile_exists = false;
			}

			// If it's a file, copy it over to the target directory.
			if ( $is_phpfile && ( $phpfile_exists || file_exists( $path ) ) ) {
				$file = array_pop( ( explode( '/components/', $path ) ) ); // Enclose in parens to pass by reference.
				$dest = $target_dir . '/components/' . $file;
				$this->ensure_directory( dirname( $dest ) );
				$insert[] = preg_replace( '%/+%', '/', $path );
				copy( $path, $dest );

			// If it's a directory, copy all files contained within.
			} else if ( is_dir( $path ) ) {

				// Get files in component directory, excluding unwanted paths.
				$files = preg_grep( '/^[\\.]{1,2}$/', scandir( $path ), PREG_GREP_INVERT );
				sort( $files ); // Ensure indexes start from zero.

				// Add files into insertion array for later.
				foreach ( $files as $file ) {
					$insert[] = preg_replace( '%/+%', '/', $path . '/' . $file );
				}

				// Copy files to build.
				$dest =  $target_dir . '/components/' . $comp;
				$this->ensure_directory( dirname( $dest ) );
				$this->copy_files( $path, $files, $dest );
			}
		}

		// Get build sources.
		$sources = $this->get_build_sources( $target_dir );

		// Replace components in sources.
		foreach ( $sources as $file ) {
			$src = $file['source'];
			$filename = str_replace( $target_dir . '/', '', $file['path'] );

			// Boolean variable, which determines if we must copy over the components included
			// in any of the files that are overridden by the type. If a type overrides a template,
			// then it is assumed that the insertion comments in that file must be in the build.
			$copy_over = in_array( $filename, $replacements );

			// Get all of the insertion comments in source.
			preg_match_all( '%<!-- components/([^\s]+)\s+-->%', $src, $matches );

			// If we have matches, then proceed
			if ( is_array( $matches ) && ! empty( $matches ) ) {
				$comments = $matches[0];

				// Iterate over each of the insertion comments.
				foreach ( $comments as $comment ) {

					// Generate the `get_teplate_part()` cals needed to insert components.
					$comp = preg_replace( '%(<!--\s+|\s+-->)%', '', $comment );
					$template = preg_replace( '/\\.php$/', '', basename( $comp ) );
					$parts = explode( '-', $template );

					// Depending on the number of words in the template filename, we generate the PHP code.
					if ( 1 === count( $parts ) ) {
						$code = sprintf( "<?php get_template_part( '%s', '%s' ); ?>", dirname( $comp ), $parts[0] );
						pre( $code );
					} else {
						$tpl = array_shift( $parts );
						$code = sprintf( "<?php get_template_part( '%s/%s', '%s' ); ?>", dirname( $comp ), $tpl, implode( '-', $parts ) );
					}

					// The component file we're working with.
					$compfile = preg_replace( '%/+%', '/', $this->components_dir . '/' . $comp );

					// If the component file is included in the `components` config -OR- we're copying over, proceed.
					if ( in_array( $compfile, $insert ) || $copy_over ) {

						// Replace the insertion comment with the actual `get_template_part()` call.
						$src = str_replace( $comment, $code, $src );

						// If we're copying over, make sure the component is copied in the build.
						if ( $copy_over ) {
							$comp_path = $this->components_dir . '/' . $comp;
							$comp_target = $target_dir . '/' . $comp;
							$this->ensure_directory( dirname( $comp_target ) );
							copy( $comp_path, $comp_target );
						}
					}
				}

				// Remove any insertion comments that are not needed.
				$src = preg_replace( '/\s*<!--\s+[^\s]+\s+-->\s*\n/', "\n", $src );

				// If the original source has been modified, then write the file. Otherwise don't.
				if ( $src !== $file['source'] ) {
					file_put_contents( $file['path'], $src );
				}
			}
		}
	}

	/**
	 * Gets the build sources and associated file data.
	 */
	public function get_build_sources( $dir ) {
		// Get all files recursively in build dir.
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir ) );
		$files = array();
		foreach( $iterator as $file ) {
			$files[] = (string) $file->getPathName();
		}

		// Filter only PHP files.
		$files = preg_grep( '/\\.php$/', $files );

		// Process file data.
		$data = array();
		foreach ( $files as $path ) {
			$data[] = array(
				'path' => $path,
				'source' => file_get_contents( $path ),

			);
		}

		// Return processed data.
		return $data;
	}

	/**
	 * Replaces files in the build from those specified by type.
	 */
	public function add_replacement_files( $type, $files, $target_dir ) {
		// Copy files to the target directory.
		$src_dir = sprintf( '%s/types/%s', $this->components_dir, $type );
		$this->copy_files( $src_dir, $files, $target_dir );
	}

	/**
	 * Adds sass includes to the build and takes care of file overrides.
	 */
	public function add_sass_includes( $type, $files, $target_dir ) {

	}

	/**
	 * Adds templates to the build.
	 */
	public function add_templates( $files, $target_dir ) {
		// Copy files to the target directory.
		$this->copy_files( $this->components_dir . '/templates', $files, $target_dir . '/templates' );
	}

	/**
	 * Removes component insertion comments from source.
	 */
	public function add_javascript( $files, $target_dir ) {
		// Copy over the files
		$this->copy_files( $this->components_dir . '/assets/js', $files, $target_dir . '/assets/js' );
	}

	/**
	 * Renders the generator type form.
	 */
	function render_types_form() {
		// Parse the types.json file so we can use the data.
		$types = $this->read_json( $this->build_dir . 'types.json' ); ?>
		<section id="generator">

			<div class="gear-set-one">
				<?php echo file_get_contents( get_template_directory() . '/assets/img/gear-set.svg' ); ?>
			</div><!-- .gear-set-one -->

			<div class="gear-set-two">
				<?php echo file_get_contents( get_template_directory() . '/assets/img/gear-set.svg' ); ?>
			</div><!-- .gear-set-two -->

			<div class="wrap">
				<h2>Build your own Components theme</h2>
				<p>Pick a type, fill out the information about your new theme, and download it.</p>
				<div id="generator-form" class="generator-form">
					<form method="POST">
						<input type="hidden" name="components_generate" value="1" />

						<div class="theme-input clear">
							<div class="generator-form-primary">
								<fieldset>
									<legend class="components-label">Theme type<span class="required"><span class="screen-reader-text">Required</span></span></legend>
									<div class="components-radio-block">
										<?php
											$i = 0;
											foreach ( $types as $key => $value ) :
												// Check our first radio as a default and add a required to it.
												if ( 0 == $i ) {
													$checked = 'checked="checked"';
													$required = ' required';
												} else {
													$checked = null;
													$required = null;
												}
												$i++;
										?>
											<div class="components-radio-group">
												<input id="<?php echo $key; ?>" class="components-input" type="radio" name="theme-type" value="<?php echo $value; ?>" <?php echo $checked; echo $required; ?>>
												<label class="components-label" for="<?php echo $key; ?>"><?php echo $value; ?></label>
											</div>
										<?php endforeach; ?>
									</div>
								</fieldset>
							</div><!-- .generator-form-primary -->

							<div class="generator-form-secondary">
								<div class="components-form-field">
									<label class="components-label" for="components-types-name">Theme Name<span class="required"><span class="screen-reader-text">Required</span></span></label>
									<input type="text" id="components-types-name" class="components-input" name="components_types_name" placeholder="Awesome Theme" required>
								</div>

								<div class="components-form-field">
									<label class="components-label" for="components-types-slug">Theme Slug</label>
									<input type="text" id="components-types-slug" class="components-input" name="components_types_slug" placeholder="awesome-theme">
								</div>

								<div class="components-form-field">
									<label class="components-label" for="components-types-author">Author Name</label>
									<input type="text" id="components-types-author" class="components-input" name="components_types_author" placeholder="Your Name">
								</div>

								<div class="components-form-field">
									<label class="components-label" for="components-types-author-uri">Author URI</label>
									<input type="url" id="components-types-author-uri" class="components-input" name="components_types_author_uri" placeholder="http://themeshaper.com/">
								</div>

								<div class="components-form-field">
									<label class="components-label" for="components-types-description">Theme description</label>
									<input type="text" id="components-types-description" class="components-input" name="components_types_description" placeholder="A brief description of your awesome theme">
								</div>

								<div class="generator-form-submit">
									<input type="submit" name="components_types_generate_submit" value="Download Theme">
									<button type="button" class="components-form-cancel">Cancel</button>
								</div><!-- .generator-form-submit -->
							</div><!-- .generator-form-secondary -->
						</div><!-- .generator-form-inputs -->
					</form>
				</div><!-- .generator-form -->
			</div><!-- .wrap -->
		</section><!-- #generator -->
	<?php }

	// Utility functions: These help the generator do its work.

	/**
	 * Copies files to a given directory
	 */
	public function copy_files( $src_dir, $files, $target_dir ) {
		// Do nothing if no files to copy
		if ( empty( $files ) ) return;

		// Make sure target directory exists.
		$this->ensure_directory( $target_dir );

		// Copy over the files
		foreach( $files as $file ) {
			copy( $src_dir . '/' . $file, $target_dir . '/' . $file );
		}
	}

	/**
	 * Copy files to temporary build directory.
	 */
	public function copy_build_files( $source_dir, $target_dir, $exclude = array() ) {
		// Bail if source directory is not a directory.
		if ( ! is_dir( $source_dir ) ) {
			return;
		}

		// Make sure target directory exists.
		$this->ensure_directory( $target_dir, true );

		// Add current and previous directory wildcards to excludes.
		$exclude = array_merge( array( '.', '..' ), $exclude );

		// Open directory handle.
		$dir = opendir( $source_dir );

		// Iterate, as long as we have files.
		$file = readdir( $dir );
		while ( false !== $file ) {
			if ( ! in_array( $file, $exclude ) ) {
				if ( is_dir( $source_dir . '/' . $file ) ) {
					// Calling the method recursively, without passing the files to exclude.
					// This has the side effect of only excluding files in the root, and not the ones in subdirectories.
					$this->copy_build_files( $source_dir . '/' . $file, $target_dir . '/' . $file );
				} else {
					copy( $source_dir . '/' . $file, $target_dir . '/' . $file );
				}
			}
			$file = readdir( $dir ); // Set file for next iteration.
		}

		// Close directory handle.
		closedir( $dir );
	}

	/**
	 * This downloads a file at a URL.
	 */
	public function download_file( $URI, $file_name ) {
		$fp = fopen( $file_name, 'w' );
		$ch = curl_init( $URI );
		curl_setopt( $ch, CURLOPT_FILE, $fp );
		$data = curl_exec( $ch );
		curl_close( $ch );
		fclose( $fp );
	}

	/**
	 * This unzips our zip from the Github repo.
	 */
	public function unzip_file( $zip_file ) {
		$path = pathinfo( realpath( $zip_file ), PATHINFO_DIRNAME );
		$zip = new ZipArchive;
		$res = $zip->open( $zip_file );
		if ( true === $res  ) {
			$zip->extractTo( $path ); // Extract it to the path we determined above.
			$zip->close();
		} else {
			die( 'Oh no! I couldn\'t open the zip: ' . $zip_file . '.' );
		}
	}

	/**
	 * Checks if a directory exists, creates it otherwise.
	 */
	public function ensure_directory( $directory, $delete_if_exists=false ) {
		if ( ! file_exists( $directory ) && ! is_dir( $directory ) ) {
			if ( ! mkdir( $directory, 0755, true ) ) {
				$this->log_message( sprintf( __( 'Error: %s directory was not able to be created.' ), $directory ) );
			}
		} else if ( $delete_if_exists && is_dir( $directory ) ) {
			$this->delete_directory( $directory );
			$this->ensure_directory( $directory );
		}
	}

	/**
	 * This deletes a file.
	 */
	public function delete_file( $URI ) {
		if ( ! unlink( $URI ) ) {
			$this->log_message( sprintf( __( 'Error: %s file was not able to be deleted.' ), $URI ) );
		}
	}

	/**
	 * Delete a directory of files.
	 */
	 function delete_directory( $directory ) {
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $files as $fileinfo ) {
			$fname = $fileinfo->isDir() ? 'rmdir' : 'unlink';
			if ( ! call_user_func( $fname, $fileinfo->getRealPath() ) ) {
				$this->log_message( sprintf( __( 'Error: %1$s function was not able to be executed. Arguments were: %2$s.' ), $fname, $fileinfo->getRealPath() ) );
			}
		}
		return rmdir( $directory );
	}

	/**
	 * Let's set an expiration on the last download and get current time.
	 */
	function set_expiration_and_go() {
		// We only need to grab the file info of one type zip file since all files are created at once.
		$file_name = $this->build_dir . $this->repo_file_name;

		// Determine if we need to hook the init method
		$hook_init = false;
		if ( file_exists( $file_name ) ) {
			$file_time_stamp = date( filemtime( $file_name ) );
			$time = time();
			$expired = 1800; // Expire cache after 30 minutes.
			if ( $this->bypass_cache ) {
				$hook_init = true; // Bypass the cache if debug filter is true
			} else {
				$hook_init = $expired <= ( $time - $file_time_stamp ) ? true : false;
			}
		} else {
			// If no file exists run the init function anyway.
			$hook_init = true;
		}

		// Only fetch theme components if it's really necessary.
		if ( $hook_init ) {
			add_action( 'wp_footer', array( $this, 'get_theme_components_init' ), 99 );
		}
	}

	/**
	 * Logs messages to debug.log in wp-content folder
	 */
    public function log_message ( $data )  {
        if ( $this->logging ) {
            if ( is_array( $data ) || is_object( $data ) ) {
                error_log( print_r( $data, true ) );
            } else {
                error_log( $data );
            }
        }
    }

    /**
     * Gets list of stylesheets to include
     */
    public function get_stylesheet_paths( $filename ) {
    	$final_matches = array();
    	$stylesheet_contents = file_get_contents( $filename );
    	preg_match_all( '/@import\s+"([^"]+)"\s*;/i', $stylesheet_contents, $matches );
    	if ( isset( $matches[1] ) && is_array( $matches[1] ) && ! empty( $matches[1] ) ) {
	    	foreach ( $matches[1] as $key => $value ) {
	    		$file_parts = explode( '/', $value );
	    		if ( is_array( $file_parts) && 1 < count( $file_parts) ) {
	    			array_push( $final_matches, sprintf( '%s/_%s.scss', array_shift( $file_parts ), array_pop( $file_parts ) ) );
	    		} else {
	    			array_push( $final_matches, sprintf( '_%s.scss', array_pop( $file_parts ) ) );
	    		}

	    	}
	    } else {
	    	$this->log_message( __( 'Error: stylesheet file was unable to be parsed and/or find SASS imports.' ) );
	    	$this->log_message( $matches );
	    }
	    return $final_matches;
    }

}

if ( ! is_admin() ) {
	new Components_Generator_Plugin;
}