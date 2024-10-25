<?php

/*
Plugin Name: Rest Options
Plugin URI: https://github.com/gencoglutugrul/wp-rest-options
Description: Exposes a custom REST API endpoint to retrieve values from wp_options by option names, secured with an API key and option restrictions.
Version: 1.0
Author: Tuğrul Gençoğlu
Author URI: https://github.com/gencoglutugrul
License: GPL2
PHP Version: >= 5.6
*/

/** @noinspection SpellCheckingInspection */
if (!defined('ABSPATH')) {
    exit;
}

class RestOptions
{
    const DELIMITER_NEW_LINE = "\n";

    /**
     * Option names.
     */
    const OPTION_NAME_API_KEY = 'rest_options_plugin_api_key';
    const OPTION_NAME_RESTRICTION_TYPE = 'rest_options_plugin_restriction_type';
    const OPTION_NAME_RESTRICTION_LIST = 'rest_options_plugin_restriction_list';

    /**
     * Option values.
     */
    const RESTRICTION_TYPE_ALLOW_ALL = 'allow_all';
    const RESTRICTION_TYPE_ALLOW_ONLY = 'allow_only';
    const RESTRICTION_TYPE_RESTRICT_ONLY = 'restrict_only';

    /**
     * Default option values.
     */
    const DEFAULT_RESTRICTION_TYPE = self::RESTRICTION_TYPE_RESTRICT_ONLY;
    const DEFAULT_RESTRICTION_LIST = self::OPTION_NAME_API_KEY;

    /**
     * Input names.
     */
    const INPUT_NAME_RESTRICTION_TYPE = 'restriction_type';
    const INPUT_NAME_RESTRICTION_LIST = 'restriction_list';
    const INPUT_NAME_SAVE_OPTIONS = 'save_options';
    const INPUT_NAME_GENERATE_API_KEY = 'generate_api_key';

    /**
     * Request body keys.
     */
    const REQUEST_BODY_KEY_OPTIONS = 'options';

	/**
	 * Routing
	 */
	const ROUTE_NAMESPACE = 'rest-options/v1';
	const ROUTE_PATH_GET_OPTIONS = '/get-options';

    public function __construct()
    {
        add_action('rest_api_init', [$this, 'registerApiRoutes']);
        add_action('admin_menu', [$this, 'registerSettingsPage']);
    }

    public function registerApiRoutes()
    {
        register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_PATH_GET_OPTIONS,
			[
                'methods' => 'POST',
                'permission_callback' => [$this, 'validateApiKey'],
                'callback' => [$this, 'handleGetOptionsRestApiRequest']
            ]
        );
    }

    public function registerSettingsPage()
    {
        add_options_page(
            'Configuration for Options Rest API',
            'Rest Options',
            'manage_options',
            'options-rest-api-configuration',
            [$this, 'renderSettingsPage']
        );
    }

    /**
     * The middleware to validate the API key.
     *
     * @param WP_REST_Request $request
     */
    public function validateApiKey($request)
    {
        $api_key = $request->get_header('x-api-key');
        $stored_key = get_option(self::OPTION_NAME_API_KEY);

        if ($api_key && hash_equals($stored_key, $api_key)) {
            return true;
        }

        return new WP_Error('unauthorized', 'Invalid API key', ['status' => 401]);
    }

    /**
     * Returns the allowed options requested by the client.
     *
     * @param WP_REST_Request $request
     */
    public function handleGetOptionsRestApiRequest($request)
    {
        $optionsRequested = $request->get_param(self::REQUEST_BODY_KEY_OPTIONS);

        if (false === is_array($optionsRequested)) {
            return new WP_Error('invalid_param', 'Options must be an array', ['status' => 400]);
        }

        $response = [];
        $restrictionType = get_option(self::OPTION_NAME_RESTRICTION_TYPE, self::DEFAULT_RESTRICTION_TYPE);
        $restrictionList = get_option(self::OPTION_NAME_RESTRICTION_LIST, self::DEFAULT_RESTRICTION_LIST);
        $restrictionListItems = array_filter(
            array_map(
                'trim',
                explode(self::DELIMITER_NEW_LINE, $restrictionList)
            )
        );

        foreach ($optionsRequested as $oneOptionRequested) {
            if ($restrictionType === self::RESTRICTION_TYPE_ALLOW_ALL) {
                $response[$oneOptionRequested] = get_option($oneOptionRequested, null);
            }

            if ($restrictionType === self::RESTRICTION_TYPE_ALLOW_ONLY) {
                if (in_array($oneOptionRequested, $restrictionListItems)) {
                    $response[$oneOptionRequested] = get_option($oneOptionRequested, null);
                }
            }

            if ($restrictionType === self::RESTRICTION_TYPE_RESTRICT_ONLY) {
                if (!in_array($oneOptionRequested, $restrictionListItems)) {
                    $response[$oneOptionRequested] = get_option($oneOptionRequested, null);
                }
            }
        }

        return rest_ensure_response($response);
    }

    public function renderSettingsPage()
    {
        if (isset($_POST[self::INPUT_NAME_GENERATE_API_KEY])) {
            $apiKey = $this->generateRandomApiKey();

            update_option(self::OPTION_NAME_API_KEY, $apiKey);
        }

        if (isset($_POST[self::INPUT_NAME_SAVE_OPTIONS])) {
            $restrictionType = $_POST[self::INPUT_NAME_RESTRICTION_TYPE];
            $restrictionList = sanitize_text_field($_POST[self::INPUT_NAME_RESTRICTION_LIST]);

            update_option(self::OPTION_NAME_RESTRICTION_LIST, $restrictionList);
            update_option(self::OPTION_NAME_RESTRICTION_TYPE, $restrictionType);
        }

        echo $this->buildSettingsPage();
    }

    private function generateRandomApiKey()
    {
        if (function_exists('random_bytes')) {
            try {
                return bin2hex(random_bytes(32));
            } catch (Exception $exception) {
                // We'll fall back to next method.
            }
        }

        if (function_exists('openssl_random_pseudo_bytes')) {
            return bin2hex(openssl_random_pseudo_bytes(32));
        }

        // Unfortunately, there is no secure way to generate a random key. We will use a less secure method.
        return hash('sha256', uniqid('', true));
    }

    private function buildSettingsPage()
    {
        return '<div class="wrap">'
            . '<h1>Options Rest  Settings</h1>'
            . $this->buildApiKeyForm()
            . $this->buildRestrictionForm()
            . '<hr>'
            . $this->buildDocumentation()
            . '</div>';
    }

    private function buildApiKeyForm()
    {
        $apiKey = get_option(self::OPTION_NAME_API_KEY, 'No API key generated yet.');

        return '<form method="POST">'
            . '<h2>API Key</h2>'
            . '<p><strong>Current API Key:</strong> ' . esc_html($apiKey) . '</p>'
            . $this->buildSubmitButton(self::INPUT_NAME_GENERATE_API_KEY, 'Generate New API Key')
            . '</form>';
    }

    private function buildRestrictionForm()
    {
        $restrictionList = get_option(self::OPTION_NAME_RESTRICTION_LIST, self::DEFAULT_RESTRICTION_LIST);
        $restrictionType = get_option(self::OPTION_NAME_RESTRICTION_TYPE, self::DEFAULT_RESTRICTION_TYPE);

        return '<form method="POST">'
            . '<h2>Allowed/Restricted Options</h2>'
            . $this->buildInputRadioAllowOnly($restrictionType)
            . $this->buildInputRadioRestrictOnly($restrictionType)
            . $this->buildInputRadioAllowAll($restrictionType)
            . $this->buildTextareaForRestrictionList($restrictionList)
            . $this->buildSubmitButton(self::INPUT_NAME_SAVE_OPTIONS, 'Save Options')
            . '</form>';
    }

    private function buildTextareaForRestrictionList($restrictionList)
    {
        return '<p><textarea rows="5" cols="50" placeholder="Enter option names, one per line" name="'
            . self::INPUT_NAME_RESTRICTION_LIST . '">' .
            esc_textarea($restrictionList)
            . '</textarea></p>';
    }

    private function buildInputRadioAllowOnly($selectedOption)
    {
        return $this->buildInputRadioForRestrictionType(
            self::RESTRICTION_TYPE_ALLOW_ONLY,
            $selectedOption,
            'Allow only these options'
        );
    }

    private function buildInputRadioRestrictOnly($selectedOption)
    {
        return $this->buildInputRadioForRestrictionType(
            self::RESTRICTION_TYPE_RESTRICT_ONLY,
            $selectedOption,
            'Restrict these options'
        );
    }

    private function buildInputRadioAllowAll($selectedOption)
    {
        return $this->buildInputRadioForRestrictionType(
            self::RESTRICTION_TYPE_ALLOW_ALL,
            $selectedOption,
            'Allow all options'
        );
    }

    private function buildInputRadioForRestrictionType(
        $value,
        $selectedOption,
        $text
    ) {
        $checked = checked($selectedOption, $value, false);

        return '<p><label>'
            . '<input type="radio" name="'
            . self::INPUT_NAME_RESTRICTION_TYPE
            . '" value="' . $value . '" ' . $checked . ' /> '
            . $text
            . '</label></p>';
    }

    private function buildSubmitButton($name, $value)
    {
        return '<input type="submit" name="' . $name . '" class="button button-primary" value="' . $value . '" />';
    }

	private function buildDocumentation() {
		$endpoint = site_url() . '/wp-json/' . self::ROUTE_NAMESPACE . self::ROUTE_PATH_GET_OPTIONS;

		return '<h2>Documentation</h2>'
            . '<p>Use the below settings to configure whether to allow or restrict options and which options to allow or restrict.</p>'
			. '<p>Example Request</p>'
			. '<pre>'
			. 'POST ' . $endpoint . ' HTTP/1.1' . "\n"
			. 'Content-Type: application/json' . "\n"
			. 'x-api-key: YOUR_API_KEY' . "\n"
			. "\n"
			. '{' . "\n"
			. '  "options": ["option_name_1", "option_name_2"]' . "\n"
			. '}' . "\n"
			. '</pre>'
			. '<p>Example Response</p>'
			. '<pre>'
			. 'HTTP/1.1 200 OK' . "\n"
			. 'Content-Type: application/json' . "\n"
			. "\n"
			. '{' . "\n"
			. '  "option_name_1": "option_value_1",' . "\n"
			. '  "option_name_2": "option_value_2"' . "\n"
			. '}' . "\n"
			. '</pre>'
			. '<p>About the restrictions:</p>'
			. '<ul>'
			. '<li><strong>Allow only these options:</strong> Only the options listed in the textarea will be allowed.</li>'
			. '<li><strong>Restrict these options:</strong> All options except the ones listed in the textarea will be allowed.</li>'
			. '<li><strong>Allow all options:</strong> All options will be allowed.</li>'
			. '</ul>'
		    . '<p>If a requested option is not allowed, it will not be included in the response.</p>'
			. '<p>If a requested option does not exist, it\'s value will be null.</p>'
			. '<p>If the API key is invalid, the response will be a 401 Unauthorized.</p>';
	}
}

new RestOptions();
