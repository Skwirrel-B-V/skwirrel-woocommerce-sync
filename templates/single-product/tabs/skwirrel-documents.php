<?php
/**
 * Product documents tab template.
 *
 * Override this template by copying it to yourtheme/woocommerce/single-product/tabs/skwirrel-documents.php
 *
 * @package Skwirrel_WC_Sync
 * @var array $documents Array of {id, url, name, type, type_label}
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
            <li>
                <?php if (!empty($doc['url'])) : ?>
                    <a href="<?php echo esc_url($doc['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($doc['name']); ?></a>
                <?php else : ?>
                    <?php echo esc_html($doc['name']); ?>
                <?php endif; ?>
                <?php if (!empty($doc['type_label'])) : ?>
                    <span class="document-type">(<?php echo esc_html($doc['type_label']); ?>)</span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
