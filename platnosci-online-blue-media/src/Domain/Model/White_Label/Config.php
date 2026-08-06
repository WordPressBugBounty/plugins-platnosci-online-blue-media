<?php

namespace Ilabs\BM_Woocommerce\Domain\Model\White_Label;

defined( 'ABSPATH' ) || exit;

class Config {

	const UNSPECIFIED_IDS = null;

	public function get_config(): array {

		$blik0_type = blue_media()
			->get_blue_media_gateway()
			->get_option( 'blik_type', 'with_redirect' );

		return [
			[
				'name'       => 'Blik',
				'position'   => 0,
				'ids'        => [ 509 ],
				'extra_html' => $blik0_type === 'blik_0_without_redirect' ? $this->get_blik0_html_info()
					: $this->get_desc_html_info( __( 'Pay comfortably using BLIK payments',
						'platnosci-online-blue-media' ) ),
				'data'       => [
					'blik0'    => $blik0_type === 'blik_0_without_redirect',
					'test_key' => 'test_value',
				],
			],

			/*[
				'name'       => __( 'Card Payment', 'platnosci-online-blue-media' ),
				'position'   => 1,
				'ids'        => [ 1500 ],
				'extra_html' => $this->get_card_html_info()
			],*/
			[
				'name'              => __( 'Card Payment', 'platnosci-online-blue-media' ),
				'position'          => 1,
				'ids'               => [ 1500 ],
				'extra_html'        => $this->get_desc_html_info( __( 'We will redirect you to the website of our partner Autopay, where you will provide your card details',
					'platnosci-online-blue-media' ) ),
				'block_description' => __( 'We will redirect you to the website of our partner Autopay, where you will provide your card details',
					'platnosci-online-blue-media' ),


			],
			[
				'name'     => __( 'Online bank transfer', 'platnosci-online-blue-media' ),
				'position' => 2,
				'ids'      => self::UNSPECIFIED_IDS,
			],
			//[ 'name' => 'Płatność Kartą One Clik', 'position' => 1, 'ids' => [ 1503 ], ],
			[
				'name'              => __( 'VisaMobile', 'platnosci-online-blue-media' ),
				'position'          => 3,
				'ids'               => [ 1523 ],
				'extra_html'        => $this->get_desc_html_info( __( 'Enter your phone number and confirm the payment in the application',
					'platnosci-online-blue-media' ) ),
				'block_description' => __( 'Enter your phone number and confirm the payment in the application',
					'platnosci-online-blue-media' ),
			],
			[
				'name'              => __( 'Google Pay', 'platnosci-online-blue-media' ),
				'position'          => 4,
				'ids'               => [ 1512 ],
				'extra_html'        => $this->get_desc_html_info( __( 'Pay without having to log in to online banking',
					'platnosci-online-blue-media' ) ),
				'block_description' => __( 'Pay without having to log in to online banking',
					'platnosci-online-blue-media' ),
			],
			[
				'name'              => __( 'Apple Pay', 'platnosci-online-blue-media' ),
				'position'          => 5,
				'ids'               => [ 1513 ],
				'extra_class'       => 'bm-apple-pay',
				'extra_script'      => $this->get_applepay_check_script(),
				'extra_html'        => $this->get_desc_html_info( __( 'Pay without having to log in to online banking',
					'platnosci-online-blue-media' ) ),
				'block_description' => __( 'Pay without having to log in to online banking',
					'platnosci-online-blue-media' ),
			],

			//[ 'name' => 'Wirtualny portfel', 'position' => 4, 'ids' => [ 778 ], ],
			[
				'name'              => __( 'Alior installments',
					'platnosci-online-blue-media' ),
				'position'          => 7,
				'ids'               => [ 1506 ],
				'extra_html'        => $this->get_alior_html_info(),
				'block_description' => $this->get_alior_html_info_for_block(),
			],
			[
				'name'              => __( 'PayPo', 'platnosci-online-blue-media' ),
				'position'          => 8,
				'ids'               => [ 705 ],
				'extra_html'        => $this->get_paypo_html_info(),
				'block_description' => $this->get_paypo_html_info_for_block(),
			],
			[
				'name'              => __( 'Spingo', 'platnosci-online-blue-media' ),
				'position'          => 9,
				'ids'               => [ 706 ],
				'extra_html'        => $this->get_desc_html_info( __( 'Deferred payment for companies',
					'platnosci-online-blue-media' ) ),
				'block_description' => __( 'Deferred payment for companies',
					'platnosci-online-blue-media' ),
			],
			[
				'name'              => __( 'BLIK Pay Later', 'platnosci-online-blue-media' ),
				'position'          => 10,
				'ids'               => [ 523 ],
				'extra_html'        => $this->get_desc_html_info( __( 'Buy now and pay within 30 days',
					'platnosci-online-blue-media' ) ),
				'block_description' => __( 'Buy now and pay within 30 days',
					'platnosci-online-blue-media' ),
			],
			//[ 'name' => 'Hub ratalny', 'position' => 10, 'ids' => [ 702 ], ],
		];
	}

	public function get_ids(): array {
		$return = [];

		foreach ( $this->get_config() as $v ) {
			if ( $v['ids'] ) {
				$return = array_merge( $return, $v['ids'] );
			}
		}

		return $return;
	}

	private function get_paypo_html_info(): string {
		return sprintf( ' <span><span class="payment-method-description">%s </span>
                            <span class="payment-method-help-text">%s</span><a href="https://start.paypo.pl/" target="_blank"><span style=""><br>%s</a></span>',
			__( 'Pick up your purchases, check them out and pay later - in 30 days or in convenient installments.',
				'platnosci-online-blue-media' ),
			__( 'We will redirect you to the PayPo partner website.',
				'platnosci-online-blue-media' ),
			__( 'Get the details.', 'platnosci-online-blue-media' )
		);
	}


	private function get_paypo_html_info_for_block(): string {
		return sprintf( '<span>%s</span><span class="atp-payment-method-help-text">%s</span><a href="https://start.paypo.pl/" target="_blank"><span style=""><br>%s</a>',
			__( 'Pick up your purchases, check them out and pay later - in 30 days or in convenient installments.',
				'platnosci-online-blue-media' ),
			__( 'We will redirect you to the PayPo partner website.',
				'platnosci-online-blue-media' ),
			__( 'Get the details.', 'platnosci-online-blue-media' )
		);
	}

	private function get_blik0_html_info(): string {
		ob_start();
		blue_media()->locate_template( 'blik_0.php' );
		$blik0_html = ob_get_contents();
		ob_end_clean();

		return $blik0_html;
	}

	private function get_gpay_html_info(): string {
		ob_start();
		blue_media()->locate_template( 'google_pay.php',
			[ 'response_data' => $this->gpay_form_data ] );
		$html = ob_get_contents();
		ob_end_clean();

		return $html;
	}

	private function get_alior_html_info(): string {
		return sprintf( ' <span><span class="payment-method-description">%s </span>
                            <a href="https://kalkulator.raty.aliorbank.pl/init?supervisor=B776&promotionList=B" target="_blank"><span style="">%s</a></span>',
			__( 'Spread the payment into convenient installments and buy without any problems.',
				'platnosci-online-blue-media' ),
			__( 'Find out more', 'platnosci-online-blue-media' )
		);
	}

	private function get_alior_html_info_for_block(): string {
		return sprintf( '<span>%s</span><a href="https://kalkulator.raty.aliorbank.pl/init?supervisor=B776&promotionList=B" target="_blank"><span>%s</a>',
			__( 'Spread the payment into convenient installments and buy without any problems.',
				'platnosci-online-blue-media' ),
			__( 'Find out more', 'platnosci-online-blue-media' )
		);
	}

	private function get_desc_html_info( string $text ): string {
		return sprintf( ' <span><span class="payment-method-description">%s </span>',
			$text
		);
	}

	public static function get_applepay_check_script(): string {
		return "<script>if (!window.ApplePaySession) {
    jQuery('.bm-apple-pay').css('display', 'none')}</script>";
	}
}
