<?php
/**
 * Product documents tab template.
 *
 * Override this template by copying it to yourtheme/woocommerce/single-product/tabs/skwirrel-documents.php
 *
 * @package Skwirrel_WC_Sync
 * @var array $documents Array of {attachment_id, name, type_code}
 * @var Skwirrel_WC_Sync_Product_Documents $instance
 */

if (!defined('ABSPATH')) {
    exit;
}

if (empty($documents)) {
    return;
}
?>
<div class="skwirrel-product-documents">
    <ul class="skwirrel-documents-list">
        <?php foreach ($documents as $doc) : ?>
            <?php
            $url = wp_get_attachment_url($doc['attachment_id']);
            $name = esc_html($doc['name']);
            $label = esc_html($instance->get_type_label($doc['type_code']));
            ?>
            <li>
                <?php if ($url) : ?>
                    <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer"><?php echo $name; ?></a>
                <?php else : ?>
                    <?php echo $name; ?>
                <?php endif; ?>
                <span class="document-type">(<?php echo $label; ?>)</span>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
