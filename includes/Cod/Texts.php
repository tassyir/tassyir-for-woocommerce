<?php
/**
 * The COD form's built-in texts in Arabic, French and English.
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce\Cod;

defined( 'ABSPATH' ) || exit;

/**
 * The form speaks the customer's language (Arabic first), whatever the site's admin language, so
 * its texts are three fixed sets (the Shopify form's copy) rather than gettext strings. The
 * merchant can override the visible ones in WooCommerce → Tassyir → Checkout form.
 */
final class Texts {

	const LANGUAGES = array( 'ar', 'fr', 'en' );

	/**
	 * Texts the merchant can override (setting key => admin label is in Cod_Settings_Page).
	 */
	const EDITABLE = array(
		'heading',
		'button_label',
		'success_message',
		'name_label',
		'phone_label',
		'phone2_label',
		'province_label',
		'town_label',
		'address_label',
		'note_label',
		'home_label',
		'stopdesk_label',
		'bundle_label',
		'cod_sublabel',
	);

	/**
	 * Every text of the form in one language.
	 *
	 * @param string $lang ar | fr | en.
	 * @return array<string, string>
	 */
	public static function defaults( string $lang ): array {
		$all = self::all();
		return $all[ in_array( $lang, self::LANGUAGES, true ) ? $lang : 'ar' ];
	}

	/**
	 * The strings the script reads from data-tsy-i18n (quote, code field, messages).
	 *
	 * @param string $lang ar | fr | en.
	 * @return array<string, string>
	 */
	public static function script( string $lang ): array {
		$texts = self::defaults( $lang );
		$out   = array();
		foreach ( $texts as $key => $value ) {
			if ( 0 === strpos( $key, 'js_' ) ) {
				$out[ substr( $key, 3 ) ] = $value;
			}
		}
		return $out;
	}

	/**
	 * The three sets. Keys starting with js_ are the script's strings.
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function all(): array {
		return array(
			'ar' => array(
				'heading'                  => 'اطلب الآن – الدفع عند الاستلام',
				'button_label'             => 'أطلب الآن',
				'success_message'          => 'تم استلام طلبك! سنتصل بك قريباً لتأكيد الطلب.',
				'name_label'               => 'الاسم الكامل',
				'name_placeholder'         => 'الاسم واللقب...',
				'phone_label'              => 'رقم الهاتف',
				'phone_placeholder'        => '05 55 55 55 55',
				'phone_hint'               => 'يجب أن يبدأ بـ 05، 06، أو 07',
				'phone2_label'             => 'رقم هاتف ثانٍ',
				'province_label'           => 'الولاية',
				'province_placeholder'     => 'اختر الولاية',
				'town_label'               => 'البلدية',
				'town_placeholder'         => 'اختر البلدية',
				'address_label'            => 'العنوان',
				'address_placeholder'      => 'العنوان...',
				'note_label'               => 'ملاحظة',
				'quantity_label'           => 'الكمية',
				'home_label'               => 'توصيل للمنزل',
				'stopdesk_label'           => 'توصيل للمكتب',
				'summary_title'            => 'ملخص الطلب',
				'summary_badge'            => 'منتج واحد',
				'subtotal_label'           => 'المجموع الفرعي',
				'shipping_label'           => 'التوصيل',
				'shipping_pending_text'    => 'تحدد عند اختيار الولاية',
				'free_shipping_label'      => 'توصيل مجاني',
				'desk_available_label'     => 'مكتب متوفر',
				'total_label'              => 'الإجمالي',
				'cod_sublabel'             => 'الدفع عند الاستلام',
				'optional_label'           => '(اختياري)',
				'upload_label'             => 'اضغط لرفع صورة',
				'bundle_label'             => 'اختر العرض المناسب',
				'bundle_item_label'        => 'القطعة',
				'bundle_savings_label'     => 'وفر',
				'js_code_toggle'           => 'لديك كود خصم؟',
				'js_code_placeholder'      => 'أدخل الكود',
				'js_code_apply'            => 'تطبيق',
				'js_code_remove'           => 'إزالة',
				'js_code_applied'          => 'تم تطبيق الكود {code}: −{amount}',
				'js_code_checking'         => 'جارٍ التحقق من الكود…',
				'js_code_unavailable'      => 'تعذّر التحقق من الكود الآن، سيُراجَع عند إرسال الطلب.',
				'js_reject_unknown'        => 'هذا الكود غير صحيح',
				'js_reject_expired'        => 'هذا الكود انتهى',
				'js_reject_not_started'    => 'هذا الكود لم يبدأ بعد',
				'js_reject_used_up'        => 'تم استعمال هذا الكود بالكامل',
				'js_reject_wrong_store'    => 'هذا الكود لا يخص هذا المتجر',
				'js_reject_not_applicable' => 'الكود لا ينطبق على سلتك',
				'js_reject_beaten_by'      => 'عرض أفضل مطبّق بالفعل',
				'js_reject_no_stacking'    => 'لا يجمع مع العرض المطبّق',
				'js_reject_ineligible'     => 'هذا الكود غير متاح لطلبك',
				'js_savings'               => 'وفّرت {amount}',
				'js_nudge_free_one'        => 'أضف قطعة واحدة للتوصيل المجاني',
				'js_nudge_free_two'        => 'أضف قطعتين للتوصيل المجاني',
				'js_nudge_free_amount'     => 'أضف {amount} للتوصيل المجاني',
				'js_nudge_next_one'        => 'أضف قطعة واحدة وتوفّر {saving} إضافية',
				'js_nudge_next_two'        => 'أضف قطعتين وتوفّر {saving} إضافية',
				'js_total_changed'         => 'تم تحديث السعر، المجموع الآن {total}. تحقق منه ثم اضغط على زر الطلب مجددًا.',
				'js_offer_ended'           => 'انتهى أحد العروض قبل إرسال طلبك. المجموع المحدَّث {total}، اضغط على زر الطلب للتأكيد.',
				'js_offer_ended_retry'     => 'انتهى أحد العروض قبل إرسال طلبك. تم تحديث السعر، راجع المجموع ثم اضغط على زر الطلب.',
				'js_price_changed'         => 'تغيّر السعر، مجموع طلبك {total}.',
				'js_code_not_applied'      => 'لم يُطبَّق الكود {code}.',
				'js_err_name'              => 'الاسم مطلوب',
				'js_err_phone'             => 'رقم الهاتف غير صالح',
				'js_err_phone2'            => 'رقم الهاتف الثاني غير صالح',
				'js_err_province'          => 'الرجاء اختيار الولاية',
				'js_err_town'              => 'الرجاء اختيار البلدية',
				'js_err_address'           => 'العنوان مطلوب',
				'js_err_required'          => '{label} مطلوب',
				'js_err_upload'            => 'تعذر رفع الصورة ({status})، حاول مرة أخرى.',
				'js_err_upload_network'    => 'تعذر رفع الصورة (شبكة)، حاول مرة أخرى.',
				'js_err_network'           => 'تعذر إرسال الطلب، الرجاء المحاولة مرة أخرى.',
				'js_err_too_many'          => 'محاولات كثيرة، الرجاء المحاولة لاحقاً.',
				'js_err_check'             => 'الرجاء التحقق من المعلومات المدخلة.',
				'js_err_generic'           => 'حدث خطأ ({detail})، الرجاء المحاولة مرة أخرى.',
			),
			'fr' => array(
				'heading'                  => 'Commandez maintenant – paiement à la livraison',
				'button_label'             => 'Commander',
				'success_message'          => 'Votre commande est reçue ! Nous vous appellerons bientôt pour la confirmer.',
				'name_label'               => 'Nom complet',
				'name_placeholder'         => 'Nom et prénom…',
				'phone_label'              => 'Téléphone',
				'phone_placeholder'        => '05 55 55 55 55',
				'phone_hint'               => 'Doit commencer par 05, 06 ou 07',
				'phone2_label'             => 'Deuxième téléphone',
				'province_label'           => 'Wilaya',
				'province_placeholder'     => 'Choisissez la wilaya',
				'town_label'               => 'Commune',
				'town_placeholder'         => 'Choisissez la commune',
				'address_label'            => 'Adresse',
				'address_placeholder'      => 'Adresse…',
				'note_label'               => 'Remarque',
				'quantity_label'           => 'Quantité',
				'home_label'               => 'Livraison à domicile',
				'stopdesk_label'           => 'Livraison au bureau',
				'summary_title'            => 'Récapitulatif',
				'summary_badge'            => '1 produit',
				'subtotal_label'           => 'Sous-total',
				'shipping_label'           => 'Livraison',
				'shipping_pending_text'    => 'Selon la wilaya choisie',
				'free_shipping_label'      => 'Livraison gratuite',
				'desk_available_label'     => 'bureau disponible',
				'total_label'              => 'Total',
				'cod_sublabel'             => 'Paiement à la livraison',
				'optional_label'           => '(facultatif)',
				'upload_label'             => 'Cliquez pour ajouter une image',
				'bundle_label'             => 'Choisissez votre offre',
				'bundle_item_label'        => 'Article',
				'bundle_savings_label'     => 'Économisez',
				'js_code_toggle'           => 'Vous avez un code promo ?',
				'js_code_placeholder'      => 'Saisissez le code',
				'js_code_apply'            => 'Appliquer',
				'js_code_remove'           => 'Retirer',
				'js_code_applied'          => 'Code {code} appliqué : −{amount}',
				'js_code_checking'         => 'Vérification du code…',
				'js_code_unavailable'      => 'Impossible de vérifier le code pour l’instant ; il sera vérifié à l’envoi de la commande.',
				'js_reject_unknown'        => 'Ce code n’est pas valide',
				'js_reject_expired'        => 'Ce code a expiré',
				'js_reject_not_started'    => 'Ce code n’est pas encore actif',
				'js_reject_used_up'        => 'Ce code a été entièrement utilisé',
				'js_reject_wrong_store'    => 'Ce code n’est pas valable dans cette boutique',
				'js_reject_not_applicable' => 'Ce code ne s’applique pas à votre panier',
				'js_reject_beaten_by'      => 'Une meilleure offre est déjà appliquée',
				'js_reject_no_stacking'    => 'Ce code ne se cumule pas avec l’offre appliquée',
				'js_reject_ineligible'     => 'Ce code n’est pas disponible pour votre commande',
				'js_savings'               => 'Vous économisez {amount}',
				'js_nudge_free_one'        => 'Ajoutez 1 article pour la livraison gratuite',
				'js_nudge_free_two'        => 'Ajoutez 2 articles pour la livraison gratuite',
				'js_nudge_free_amount'     => 'Ajoutez {amount} pour la livraison gratuite',
				'js_nudge_next_one'        => 'Ajoutez 1 article et économisez {saving} de plus',
				'js_nudge_next_two'        => 'Ajoutez 2 articles et économisez {saving} de plus',
				'js_total_changed'         => 'Le prix a été mis à jour : le total est maintenant {total}. Vérifiez-le, puis appuyez à nouveau sur le bouton.',
				'js_offer_ended'           => 'Une offre s’est terminée avant l’envoi. Nouveau total : {total}. Appuyez sur le bouton pour confirmer.',
				'js_offer_ended_retry'     => 'Une offre s’est terminée avant l’envoi. Le prix a été mis à jour : vérifiez le total, puis appuyez sur le bouton.',
				'js_price_changed'         => 'Le prix a changé : le total de votre commande est {total}.',
				'js_code_not_applied'      => 'Le code {code} n’a pas été appliqué.',
				'js_err_name'              => 'Le nom est obligatoire',
				'js_err_phone'             => 'Numéro de téléphone invalide',
				'js_err_phone2'            => 'Deuxième numéro de téléphone invalide',
				'js_err_province'          => 'Veuillez choisir la wilaya',
				'js_err_town'              => 'Veuillez choisir la commune',
				'js_err_address'           => 'L’adresse est obligatoire',
				'js_err_required'          => '{label} : obligatoire',
				'js_err_upload'            => 'Échec de l’envoi de l’image ({status}), réessayez.',
				'js_err_upload_network'    => 'Échec de l’envoi de l’image (réseau), réessayez.',
				'js_err_network'           => 'Impossible d’envoyer la commande, veuillez réessayer.',
				'js_err_too_many'          => 'Trop de tentatives, réessayez plus tard.',
				'js_err_check'             => 'Veuillez vérifier les informations saisies.',
				'js_err_generic'           => 'Une erreur s’est produite ({detail}), veuillez réessayer.',
			),
			'en' => array(
				'heading'                  => 'Order now – cash on delivery',
				'button_label'             => 'Order now',
				'success_message'          => 'Your order is received! We will call you soon to confirm it.',
				'name_label'               => 'Full name',
				'name_placeholder'         => 'First and last name…',
				'phone_label'              => 'Phone',
				'phone_placeholder'        => '05 55 55 55 55',
				'phone_hint'               => 'Must start with 05, 06 or 07',
				'phone2_label'             => 'Second phone',
				'province_label'           => 'Wilaya',
				'province_placeholder'     => 'Choose the wilaya',
				'town_label'               => 'Commune',
				'town_placeholder'         => 'Choose the commune',
				'address_label'            => 'Address',
				'address_placeholder'      => 'Address…',
				'note_label'               => 'Note',
				'quantity_label'           => 'Quantity',
				'home_label'               => 'Home delivery',
				'stopdesk_label'           => 'Desk pickup',
				'summary_title'            => 'Order summary',
				'summary_badge'            => '1 product',
				'subtotal_label'           => 'Subtotal',
				'shipping_label'           => 'Delivery',
				'shipping_pending_text'    => 'Set when you choose the wilaya',
				'free_shipping_label'      => 'Free delivery',
				'desk_available_label'     => 'desk available',
				'total_label'              => 'Total',
				'cod_sublabel'             => 'Cash on delivery',
				'optional_label'           => '(optional)',
				'upload_label'             => 'Click to upload an image',
				'bundle_label'             => 'Choose your offer',
				'bundle_item_label'        => 'Item',
				'bundle_savings_label'     => 'Save',
				'js_code_toggle'           => 'Have a discount code?',
				'js_code_placeholder'      => 'Enter code',
				'js_code_apply'            => 'Apply',
				'js_code_remove'           => 'Remove',
				'js_code_applied'          => 'Code {code} applied: −{amount}',
				'js_code_checking'         => 'Checking the code…',
				'js_code_unavailable'      => 'Couldn’t check the code right now; it will be checked when you place the order.',
				'js_reject_unknown'        => 'This code isn’t valid',
				'js_reject_expired'        => 'This code has expired',
				'js_reject_not_started'    => 'This code isn’t active yet',
				'js_reject_used_up'        => 'This code has been fully used',
				'js_reject_wrong_store'    => 'This code isn’t for this store',
				'js_reject_not_applicable' => 'This code doesn’t apply to your cart',
				'js_reject_beaten_by'      => 'A better offer is already applied',
				'js_reject_no_stacking'    => 'This code can’t be combined with the offer already applied',
				'js_reject_ineligible'     => 'This code isn’t available for your order',
				'js_savings'               => 'You save {amount}',
				'js_nudge_free_one'        => 'Add 1 more item for free delivery',
				'js_nudge_free_two'        => 'Add 2 more items for free delivery',
				'js_nudge_free_amount'     => 'Add {amount} more for free delivery',
				'js_nudge_next_one'        => 'Add 1 more item and save {saving} extra',
				'js_nudge_next_two'        => 'Add 2 more items and save {saving} extra',
				'js_total_changed'         => 'The price was updated: the total is now {total}. Check it, then press the order button again.',
				'js_offer_ended'           => 'An offer ended before your order was sent. New total: {total}. Press the order button to confirm.',
				'js_offer_ended_retry'     => 'An offer ended before your order was sent. The price was updated: check the total, then press the order button.',
				'js_price_changed'         => 'The price changed: your order total is {total}.',
				'js_code_not_applied'      => 'The code {code} was not applied.',
				'js_err_name'              => 'Your name is required',
				'js_err_phone'             => 'Invalid phone number',
				'js_err_phone2'            => 'Invalid second phone number',
				'js_err_province'          => 'Please choose the wilaya',
				'js_err_town'              => 'Please choose the commune',
				'js_err_address'           => 'The address is required',
				'js_err_required'          => '{label} is required',
				'js_err_upload'            => 'The image could not be uploaded ({status}), try again.',
				'js_err_upload_network'    => 'The image could not be uploaded (network), try again.',
				'js_err_network'           => 'The order could not be sent, please try again.',
				'js_err_too_many'          => 'Too many attempts, please try again later.',
				'js_err_check'             => 'Please check the information you entered.',
				'js_err_generic'           => 'An error occurred ({detail}), please try again.',
			),
		);
	}
}
