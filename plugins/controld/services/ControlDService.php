<?php
/**
 * ControlD Service Layer
 * Provides API for other plugins and themes to interact with ControlD functionality
 */
class ControlDService {
    private $helper;
    private static $instance;
    
    private function __construct() {
        require_once(PathHelper::getIncludePath('plugins/controld/includes/ControlDHelper.php'));
        $this->helper = new ControlDHelper();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getDevicesForUser($user_id) {
        return $this->helper->getUserDevices($user_id);
    }
    
    public function createProfile($user_id, $profile_data) {
        return $this->helper->createProfile($user_id, $profile_data);
    }
    
    public function getFilteringStats($user_id) {
        return $this->helper->getFilteringStats($user_id);
    }
    
    public function isUserActive($user_id) {
        return $this->helper->isUserActive($user_id);
    }
    
    /**
     * Get user's ControlD account
     */
    public function getUserAccount($user_id) {
        require_once(PathHelper::getIncludePath('plugins/controld/data/ctldaccount_class.php'));
        $accounts = new MultiCtldAccount(['cta_usr_user_id' => $user_id]);
        if ($accounts->count_all() > 0) {
            $accounts->load();
            return $accounts->get(0);
        }
        return null;
    }
    
    /**
     * Check if user has ControlD subscription
     */
    public function hasSubscription($user_id) {
        $account = $this->getUserAccount($user_id);
        return $account !== null && $account->get('cta_status') === 'active';
    }
    
    /**
     * Get user's devices
     */
    public function getUserDevices($user_id) {
        require_once(PathHelper::getIncludePath('plugins/controld/data/ctlddevice_class.php'));
        $devices = new MultiCtldDevice(['ctd_usr_user_id' => $user_id]);
        if ($devices->count_all() > 0) {
            $devices->load();
            return $devices;
        }
        return new MultiCtldDevice(); // Return empty collection
    }
    
    /**
     * Get user's profiles
     */
    public function getUserProfiles($user_id) {
        require_once(PathHelper::getIncludePath('plugins/controld/data/ctldprofile_class.php'));
        $profiles = new MultiCtldProfile(['ctp_usr_user_id' => $user_id]);
        if ($profiles->count_all() > 0) {
            $profiles->load();
            return $profiles;
        }
        return new MultiCtldProfile(); // Return empty collection
    }
    
    /**
     * Create new device for user
     */
    public function createDevice($user_id, $device_data) {
        require_once(PathHelper::getIncludePath('plugins/controld/data/ctlddevice_class.php'));
        $device = new CtldDevice(NULL);
        $device->set('ctd_usr_user_id', $user_id);
        foreach ($device_data as $key => $value) {
            $device->set($key, $value);
        }
        $device->prepare();
        return $device->save();
    }
    
    /**
     * Get ControlD API integration status
     */
    public function getApiStatus() {
        return $this->helper->testApiConnection();
    }
}