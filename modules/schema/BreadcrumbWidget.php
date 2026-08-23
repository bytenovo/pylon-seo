<?php
namespace Pylon\Core\Modules\Schema;
defined('ABSPATH') || exit;
class BreadcrumbWidget extends \WP_Widget {
    public function __construct() {
        parent::__construct(
            'pylon_breadcrumb_widget',
            __('Pylon Breadcrumbs', 'pylon-seo'),
            ['description' => __('Display breadcrumb navigation with Schema.org markup.', 'pylon-seo')]
        );
    }

    public function widget($args, $instance): void {
        $title = !empty($instance['title']) ? apply_filters('widget_title', $instance['title']) : '';
        $engine = new SchemaEngine();
        $breadcrumbs = $engine->render_frontend_breadcrumbs();
        if (!$breadcrumbs) return;
        echo wp_kses($args['before_widget'], SchemaEngine::widget_wrapper_allowed_html());
        if ($title) {
            echo wp_kses($args['before_title'], SchemaEngine::widget_wrapper_allowed_html()) . esc_html($title) . wp_kses($args['after_title'], SchemaEngine::widget_wrapper_allowed_html());
        }
        echo wp_kses($breadcrumbs, SchemaEngine::BREADCRUMB_ALLOWED_HTML);
        echo wp_kses($args['after_widget'], SchemaEngine::widget_wrapper_allowed_html());
    }

    public function form($instance): void {
        $title = !empty($instance['title']) ? $instance['title'] : '';
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>"><?php esc_html_e('Title:', 'pylon-seo'); ?></label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>" name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text" value="<?php echo esc_attr($title); ?>">
        </p>
        <?php
    }

    public function update($new_instance, $old_instance): array {
        $instance = [];
        $instance['title'] = sanitize_text_field($new_instance['title'] ?? '');
        return $instance;
    }
}
