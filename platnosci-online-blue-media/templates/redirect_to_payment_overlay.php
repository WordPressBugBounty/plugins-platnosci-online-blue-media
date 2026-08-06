<div class="bm-redirect-overlay">
    <h2>
		<?php

defined( 'ABSPATH' ) || exit;
		esc_html_e( "In a while you'll be redirected to the payment page screen",
			"platnosci-online-blue-media" ); ?>
    </h2>

    <span><?php
		esc_html_e( "Time to redirect:",
			"platnosci-online-blue-media" ); ?>&nbsp;<span
                class="bm-redirect-counter"></span></span>


</div>

<script>

    jQuery(document).ready(function () {
        var counter = 5;
        var interval = setInterval(function () {

            if (counter >= 0) {
                jQuery('.bm-redirect-counter').text(counter + 's');
            }

            if (counter === 0) {
                clearInterval(interval);
                document.getElementById('paymentForm').submit();
            }

            counter--;

        }, 1000);
    });

</script>