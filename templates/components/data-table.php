<?php
/** Sortable/searchable data table component. Variables: $opts (array). */
if ( ! defined( 'ABSPATH' ) ) exit;
$table_id = 'wsp-table-' . wp_unique_id();
?>
<div class="wsp-table-wrap" id="<?php echo esc_attr( $table_id ); ?>">
    <?php if ( $opts['searchable'] ) : ?>
        <div class="wsp-table-toolbar">
            <input type="text" class="wsp-table-search" placeholder="<?php esc_attr_e( 'Поиск...', 'flavor-worldstat' ); ?>" data-target="<?php echo esc_attr( $table_id ); ?>">
            <?php if ( $opts['exportable'] ) : ?>
                <button class="wsp-btn wsp-btn-sm wsp-table-export" data-target="<?php echo esc_attr( $table_id ); ?>"><?php esc_html_e( 'CSV', 'flavor-worldstat' ); ?></button>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <table class="wsp-table<?php echo $opts['sortable'] ? ' wsp-sortable' : ''; ?>">
        <thead>
            <tr>
                <?php foreach ( $opts['headers'] as $h ) : ?>
                    <th><?php echo esc_html( $h ); ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php $allow_html = ! empty( $opts['allow_html'] ); ?>
            <?php foreach ( $opts['rows'] as $row ) : ?>
                <tr>
                    <?php foreach ( $row as $cell ) : ?>
                        <td><?php echo $allow_html ? wp_kses_post( $cell ) : esc_html( $cell ); ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
