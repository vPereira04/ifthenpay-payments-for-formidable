<?php
/**
 * @package Ifthenpay\Formidable
 */

namespace Ifthenpay\Formidable\Mail;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'You are not allowed to call this page directly.' );
}

/**
 * Sends the "Request Activation" email to ifthenpay support.
 *
 * Static-only, mirroring every other ifthenpay plugin's Mail helper — see
 * `ifthenpay-payments-for-paid-memberships-pro`'s own §8.5 in the project
 * blueprint for the full cross-plugin contract.
 */
final class IfthenpayEmailHelper {

	/**
	 * The real ifthenpay support address stays commented out until the team
	 * explicitly says otherwise. Every integration currently sends to the
	 * v.pereira address during development/testing. Never flip these, never
	 * delete the commented line.
	 */
	const SUPPORT_EMAIL = 'v.pereira.contacto@gmail.com';
	// const SUPPORT_EMAIL = 'suporte@ifthenpay.com';

	/**
	 * @return void
	 */
	private function __construct() {}

	/**
	 * @param array{
	 *   gateway_key: string,
	 *   entity: string,
	 *   backoffice_key: string,
	 *   customer_email: string,
	 *   site_url: string,
	 *   site_name: string,
	 *   wp_version: string,
	 *   frm_version: string,
	 *   plugin_version: string
	 * } $data
	 *
	 * @return bool
	 */
	public static function send_activation_email( array $data ) {
		$entity    = strtoupper( sanitize_text_field( isset( $data['entity'] ) ? $data['entity'] : '' ) );
		$site_url  = esc_url_raw( isset( $data['site_url'] ) ? $data['site_url'] : home_url( '/' ) );
		$recipient = self::get_support_email( $data );

		$subject = sprintf( '[dev_ifthenpay] [%s]: Ativacao de Servico', $entity );

		$items = array(
			'Chave de acesso ao backoffice:' => esc_html( isset( $data['backoffice_key'] ) ? $data['backoffice_key'] : '' ),
			'Gateway Key:'                   => esc_html( isset( $data['gateway_key'] ) ? $data['gateway_key'] : '' ),
			'Email Cliente:'                 => esc_html( isset( $data['customer_email'] ) ? $data['customer_email'] : '' ),
			'Metodo a ativar:'               => esc_html( $entity ),
			'Loja online:'                   => esc_url( $site_url ),
			'Plataforma ecommerce:'          => sprintf(
				'WordPress %s / Formidable Forms v%s',
				esc_html( isset( $data['wp_version'] ) ? $data['wp_version'] : '' ),
				esc_html( isset( $data['frm_version'] ) ? $data['frm_version'] : '' )
			),
			'Versao do Modulo ifthenpay:'    => esc_html( isset( $data['plugin_version'] ) ? $data['plugin_version'] : '' ),
			'Atualizar Conta Cliente:'       => 'Apos adicionar o metodo nao precisa tomar mais nenhuma acao, este metodo ficara disponivel para selecao na pagina de configuracao da extensao.',
		);

		ob_start();
		?>
		<div style="font-family:Arial,sans-serif;color:#333;background-color:#f9f9f9;padding:20px;border:1px solid #e0e0e0;border-radius:6px;max-width:600px;margin:auto;">
			<h2 style="margin-top:0;font-size:20px;line-height:1.2;">
				Ativar método de pagamento para a Gateway
				<span style="color:#d32f2f;"><?php echo esc_html( isset( $data['gateway_key'] ) ? $data['gateway_key'] : '' ); ?></span>
			</h2>
			<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;">
				<?php foreach ( $items as $label => $value ) : ?>
					<tr>
						<td style="padding:8px 0;vertical-align:top;width:200px;font-weight:bold;"><?php echo esc_html( $label ); ?></td>
						<td style="padding:8px 0;"><?php echo esc_html( $value ); ?></td>
					</tr>
				<?php endforeach; ?>
			</table>
			<p style="margin-top:20px;font-size:12px;color:#777;text-align:center;">
				Pedido gerado automaticamente pelo módulo ifthenpay
			</p>
		</div>
		<?php
		$body = (string) ob_get_clean();

		$host    = wp_parse_url( $site_url, PHP_URL_HOST );
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . esc_html( isset( $data['site_name'] ) ? $data['site_name'] : '' ) . ' <no-reply@' . $host . '>',
		);

		return wp_mail( $recipient, $subject, $body, $headers );
	}

	/**
	 * @param array<string, string> $data
	 *
	 * @return string
	 */
	private static function get_support_email( array $data ) {
		$email = apply_filters( 'iftp_frm_support_email', self::SUPPORT_EMAIL, $data );

		return is_string( $email ) && is_email( $email ) ? $email : self::SUPPORT_EMAIL;
	}
}
