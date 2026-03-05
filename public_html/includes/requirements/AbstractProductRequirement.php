<?php
/**
 * AbstractProductRequirement
 *
 * Base class for all product requirements. Subclasses override the methods they need.
 * Also serves as the registry — maintains a static map of class_name => file_path.
 *
 * Core classes are registered at the bottom of this file.
 * Plugin classes register themselves via AbstractProductRequirement::register().
 *
 * @version 2.0
 */
abstract class AbstractProductRequirement {

    /** @var array Configuration from pri_config JSON */
    protected $config;

    /** @var string Human-readable label — override via const LABEL in subclasses */
    const LABEL = 'Requirement';

    // ─── Registry ────────────────────────────────────────────────────

    /** @var array Map of class_name => file_path */
    private static $registry = [];

    /**
     * Register a requirement class.
     * Called at the bottom of this file for core classes.
     * Plugins call this from their requirement files.
     *
     * @param string $class_name
     * @param string $file_path Absolute path to the class file
     */
    public static function register($class_name, $file_path) {
        self::$registry[$class_name] = $file_path;
    }

    /**
     * Check if a requirement class is registered.
     */
    public static function has($class_name) {
        return isset(self::$registry[$class_name]);
    }

    /**
     * Get all registered requirement class names.
     * @return array Map of class_name => file_path
     */
    public static function getAll() {
        return self::$registry;
    }

    /**
     * Create an instance of a requirement class with the given config.
     *
     * @param string $class_name
     * @param array $config Config from pri_config JSON
     * @return AbstractProductRequirement
     */
    public static function createInstance($class_name, array $config = []) {
        if (!isset(self::$registry[$class_name])) {
            throw new Exception("Requirement class '$class_name' not found in registry.");
        }

        if (!class_exists($class_name, false)) {
            require_once(self::$registry[$class_name]);
        }

        return new $class_name($config);
    }

    /**
     * Get all requirement instances for a product, ordered by pri_order.
     *
     * @param int $product_id
     * @return AbstractProductRequirement[]
     */
    public static function getProductRequirements($product_id) {
        require_once(PathHelper::getIncludePath('data/product_requirement_instances_class.php'));

        $requirements = [];

        $instances = new MultiProductRequirementInstance(
            ['product_id' => $product_id, 'deleted' => false],
            ['pri_order' => 'ASC']
        );
        $instances->load();

        foreach ($instances as $pri) {
            $class_name = $pri->get('pri_class_name');
            $config = json_decode($pri->get('pri_config'), true) ?: [];

            if (self::has($class_name)) {
                $requirements[] = self::createInstance($class_name, $config);
            } else {
                error_log("AbstractProductRequirement: Unknown requirement class '$class_name' for product $product_id");
            }
        }

        return $requirements;
    }

    /**
     * Get all available requirements grouped for the admin UI.
     *
     * @return array ['system' => [class_name => label, ...], 'questions' => [question_id => title, ...]]
     */
    public static function getGrouped() {
        $system = [];
        $questions = [];

        foreach (self::$registry as $class_name => $file_path) {
            if ($class_name === 'QuestionRequirement') {
                continue;
            }

            if (!class_exists($class_name, false)) {
                require_once($file_path);
            }
            $system[$class_name] = defined("$class_name::LABEL") ? $class_name::LABEL : $class_name;
        }

        // Questions come from the database
        require_once(PathHelper::getIncludePath('data/questions_class.php'));
        $all_questions = new MultiQuestion(['deleted' => false, 'published' => true]);
        $all_questions->load();

        foreach ($all_questions as $question) {
            $questions[$question->key] = $question->get('qst_question');
        }

        return [
            'system' => $system,
            'questions' => $questions,
        ];
    }

    // ─── Instance methods (defaults — subclasses override as needed) ─

    public function __construct(array $config = []) {
        $this->config = $config;
    }

    public function get_label() {
        return static::LABEL;
    }

    /** Render form fields for this requirement. */
    public function render_fields($formwriter, $product, $existing_data = []) {}

    /** Validate submitted data. Return array of error strings (empty = valid). */
    public function validate($post_data, $product) { return []; }

    /** Process submitted data. Return [data_array, display_array]. */
    public function process($post_data, $product, $order_detail, $user) { return [[], []]; }

    /** Return data for admin/report display. */
    public function get_display_data($order_detail, $user) { return []; }

    /** Does this requirement affect pricing? */
    public function affects_pricing(): bool { return false; }

    /** Get modified price (only called if affects_pricing() is true). */
    public function get_modified_price($post_data, $product, $base_price) { return $base_price; }

    /** Client-side validation rules for JoineryValidation. */
    public function get_validation_info() { return null; }

    /** Custom JavaScript for form fields (without script tags). */
    public function get_javascript(): string { return ''; }

    /** Post-purchase hook — called after successful payment. */
    public function post_purchase($data, $order_item, $user, $order) {}
}

// ─── Core requirement class registration ─────────────────────────────
$req_dir = __DIR__ . '/';
AbstractProductRequirement::register('FullNameRequirement', $req_dir . 'FullNameRequirement.php');
AbstractProductRequirement::register('EmailRequirement', $req_dir . 'EmailRequirement.php');
AbstractProductRequirement::register('PhoneNumberRequirement', $req_dir . 'PhoneNumberRequirement.php');
AbstractProductRequirement::register('DOBRequirement', $req_dir . 'DOBRequirement.php');
AbstractProductRequirement::register('AddressRequirement', $req_dir . 'AddressRequirement.php');
AbstractProductRequirement::register('UserPriceRequirement', $req_dir . 'UserPriceRequirement.php');
AbstractProductRequirement::register('NewsletterSignupRequirement', $req_dir . 'NewsletterSignupRequirement.php');
AbstractProductRequirement::register('QuestionRequirement', $req_dir . 'QuestionRequirement.php');
