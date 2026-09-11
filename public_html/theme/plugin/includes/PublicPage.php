<?php
require_once(PathHelper::getIncludePath('includes/PublicPageBase.php'));

class PublicPage extends PublicPageBase {
    protected function getTableClasses() {
        return [
            'wrapper' => 'table-responsive',
            'table' => 'table table-striped',
            'header' => 'thead-light'
        ];
    }
}
?>