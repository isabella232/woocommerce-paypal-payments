<?php
/**
 * The PurchaseUnit factory.
 *
 * @package Inpsyde\PayPalCommerce\ApiClient\Factory
 */

declare(strict_types=1);

namespace Inpsyde\PayPalCommerce\ApiClient\Factory;

use Inpsyde\PayPalCommerce\ApiClient\Entity\Item;
use Inpsyde\PayPalCommerce\ApiClient\Entity\PurchaseUnit;
use Inpsyde\PayPalCommerce\ApiClient\Exception\RuntimeException;
use Inpsyde\PayPalCommerce\ApiClient\Repository\PayeeRepository;

/**
 * Class PurchaseUnitFactory
 */
class PurchaseUnitFactory {

	/**
	 * The amount factory.
	 *
	 * @var AmountFactory
	 */
	private $amount_factory;

	/**
	 * The payee repository.
	 *
	 * @var PayeeRepository
	 */
	private $payee_repository;

	/**
	 * The payee factory.
	 *
	 * @var PayeeFactory
	 */
	private $payee_factory;

	/**
	 * The item factory.
	 *
	 * @var ItemFactory
	 */
	private $item_factory;

	/**
	 * The shipping factory.
	 *
	 * @var ShippingFactory
	 */
	private $shipping_factory;

	/**
	 * The payments factory.
	 *
	 * @var PaymentsFactory
	 */
	private $payments_factory;

	/**
	 * The Prefix.
	 *
	 * @var string
	 */
	private $prefix;

	/**
	 * PurchaseUnitFactory constructor.
	 *
	 * @param AmountFactory   $amount_factory The amount factory.
	 * @param PayeeRepository $payee_repository The Payee repository.
	 * @param PayeeFactory    $payee_factory The Payee factory.
	 * @param ItemFactory     $item_factory The item factory.
	 * @param ShippingFactory $shipping_factory The shipping factory.
	 * @param PaymentsFactory $payments_factory The payments factory.
	 * @param string          $prefix The prefix.
	 */
	public function __construct(
		AmountFactory $amount_factory,
		PayeeRepository $payee_repository,
		PayeeFactory $payee_factory,
		ItemFactory $item_factory,
		ShippingFactory $shipping_factory,
		PaymentsFactory $payments_factory,
		string $prefix = 'WC-'
	) {

		$this->amount_factory   = $amount_factory;
		$this->payee_repository = $payee_repository;
		$this->payee_factory    = $payee_factory;
		$this->item_factory     = $item_factory;
		$this->shipping_factory = $shipping_factory;
		$this->payments_factory = $payments_factory;
		$this->prefix           = $prefix;
	}

	/**
	 * Creates a PurchaseUnit based off a Woocommerce order.
	 *
	 * @param \WC_Order $order The order.
	 *
	 * @return PurchaseUnit
	 */
	public function from_wc_order( \WC_Order $order ): PurchaseUnit {
		$amount   = $this->amount_factory->from_wc_order( $order );
		$items    = $this->item_factory->from_wc_order( $order );
		$shipping = $this->shipping_factory->from_wc_order( $order );
		if (
			empty( $shipping->address()->country_code() ) ||
			( $shipping->address()->country_code() && ! $shipping->address()->postal_code() )
		) {
			$shipping = null;
		}
		$reference_id    = 'default';
		$description     = '';
		$payee           = $this->payee_repository->payee();
		$wc_order_id     = $order->get_id();
		$custom_id       = $this->prefix . $wc_order_id;
		$invoice_id      = $this->prefix . $wc_order_id;
		$soft_descriptor = '';
		$purchase_unit   = new PurchaseUnit(
			$amount,
			$items,
			$shipping,
			$reference_id,
			$description,
			$payee,
			$custom_id,
			$invoice_id,
			$soft_descriptor
		);
		return $purchase_unit;
	}

	/**
	 * Creates a PurchaseUnit based off a Woocommerce cart.
	 *
	 * @param \WC_Cart $cart The cart.
	 *
	 * @return PurchaseUnit
	 */
	public function from_wc_cart( \WC_Cart $cart ): PurchaseUnit {
		$amount = $this->amount_factory->from_wc_cart( $cart );
		$items  = $this->item_factory->from_wc_cart( $cart );

		$shipping = null;
		$customer = \WC()->customer;
		if ( is_a( $customer, \WC_Customer::class ) ) {
			$shipping = $this->shipping_factory->from_wc_customer( \WC()->customer );
			if (
				! $shipping->address()->country_code()
				|| ( $shipping->address()->country_code() && ! $shipping->address()->postal_code() )
			) {
				$shipping = null;
			}
		}

		$reference_id = 'default';
		$description  = '';

		$payee = $this->payee_repository->payee();

		$custom_id       = '';
		$invoice_id      = '';
		$soft_descriptor = '';
		$purchase_unit   = new PurchaseUnit(
			$amount,
			$items,
			$shipping,
			$reference_id,
			$description,
			$payee,
			$custom_id,
			$invoice_id,
			$soft_descriptor
		);

		return $purchase_unit;
	}

	/**
	 * Builds a Purchase unit based off a PayPal JSON response.
	 *
	 * @param \stdClass $data The JSON object.
	 *
	 * @return PurchaseUnit
	 * @throws RuntimeException When JSON object is malformed.
	 */
	public function from_paypal_response( \stdClass $data ): PurchaseUnit {
		if ( ! isset( $data->reference_id ) || ! is_string( $data->reference_id ) ) {
			throw new RuntimeException(
				__( 'No reference ID given.', 'paypal-for-woocommerce' )
			);
		}

		$amount          = $this->amount_factory->from_paypal_response( $data->amount );
		$description     = ( isset( $data->description ) ) ? $data->description : '';
		$custom_id       = ( isset( $data->custom_id ) ) ? $data->custom_id : '';
		$invoice_id      = ( isset( $data->invoice_id ) ) ? $data->invoice_id : '';
		$soft_descriptor = ( isset( $data->soft_descriptor ) ) ? $data->soft_descriptor : '';
		$items           = array();
		if ( isset( $data->items ) && is_array( $data->items ) ) {
			$items = array_map(
				function ( \stdClass $item ): Item {
					return $this->item_factory->from_paypal_response( $item );
				},
				$data->items
			);
		}
		$payee    = isset( $data->payee ) ? $this->payee_factory->from_paypal_response( $data->payee ) : null;
		$shipping = null;
		try {
			if ( isset( $data->shipping ) ) {
				$shipping = $this->shipping_factory->from_paypal_response( $data->shipping );
			}
		} catch ( RuntimeException $error ) {
			;
		}
		$payments = null;
		try {
			if ( isset( $data->payments ) ) {
				$payments = $this->payments_factory->from_paypal_response( $data->payments );
			}
		} catch ( RuntimeException $error ) {
			;
		}

		$purchase_unit = new PurchaseUnit(
			$amount,
			$items,
			$shipping,
			$data->reference_id,
			$description,
			$payee,
			$custom_id,
			$invoice_id,
			$soft_descriptor,
			$payments
		);
		return $purchase_unit;
	}
}