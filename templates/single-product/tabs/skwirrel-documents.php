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
        <?php foreach ($documents as $skwirrel_doc) : ?>
            <?php
            $skwirrel_doc_url   = wp_get_attachment_url( $skwirrel_doc['attachment_id'] );
            $skwirrel_doc_name  = esc_html( $skwirrel_doc['name'] );
            $skwirrel_doc_label = esc_html( $instance->get_type_label( $skwirrel_doc['type_code'] ) );
            ?>
            <li>
                <?php if ( $skwirrel_doc_url ) : ?>
                    <a href="<?php echo esc_url( $skwirrel_doc_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $skwirrel_doc_name ); ?></a>
                <?php else : ?>
                    <?php echo esc_html( $skwirrel_doc_name ); ?>
                <?php endif; ?>
                <span class="document-type">(<?php echo esc_html( $skwirrel_doc_label ); ?>)</span>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
