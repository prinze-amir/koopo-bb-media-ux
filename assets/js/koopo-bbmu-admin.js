jQuery(function($){
  var $form = $('.koopo-admin__form');
  if (!$form.length) return;

  var $headings = $form.find('h2');
  if (!$headings.length) return;

  $headings.each(function(i){
    var $h = $(this);
    var $section = $('<div class="koopo-admin__section" data-section="section-' + i + '"></div>');
    $h.before($section);
    $section.append($h);

    var $next = $section.next();
    while ($next.length && !$next.is('h2')) {
      var $move = $next;
      $next = $next.next();
      $section.append($move);
    }
  });

  function activate(section){
    $('.koopo-admin__nav-item').removeClass('is-active');
    $('.koopo-admin__nav-item[data-section="' + section + '"]').addClass('is-active');
    $('.koopo-admin__section').hide();
    $('.koopo-admin__section[data-section="' + section + '"]').show();
  }

  $('.koopo-admin__nav-item').on('click', function(e){
    e.preventDefault();
    var section = $(this).data('section');
    if (!section) return;
    activate(section);
  });

  activate('section-0');
});
