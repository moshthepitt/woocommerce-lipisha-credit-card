jQuery(function(jQuery) {
  jQuery('[data-numeric]').payment('restrictNumeric');
  jQuery('.cc-number').payment('formatCardNumber');
  jQuery('.cc-exp').payment('formatCardExpiry');
  jQuery('.cc-cvc').payment('formatCardCVC');

  jQuery.fn.toggleInputError = function(erred) {
    this.parent('.form-group').toggleClass('has-error', erred);
    return this;
  };

  jQuery('form.checkout').submit(function(e) {
    e.preventDefault();

    var cardType = jQuery.payment.cardType(jQuery('.cc-number').val());
    jQuery('.cc-number').toggleInputError(!jQuery.payment.validateCardNumber(jQuery('.cc-number').val()));
    jQuery('.cc-exp').toggleInputError(!jQuery.payment.validateCardExpiry(jQuery('.cc-exp').payment('cardExpiryVal')));
    jQuery('.cc-cvc').toggleInputError(!jQuery.payment.validateCardCVC(jQuery('.cc-cvc').val(), cardType));
    jQuery('.cc-brand').text(cardType);

    jQuery('.lipisha-validation').removeClass('text-danger text-success');
    jQuery('.lipisha-validation').addClass(jQuery('.has-error').length ? 'text-danger' : 'text-success');
  });

});