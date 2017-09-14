<?php
$defaultInputClass = $containerType = 'cookie';
include __DIR__.'/field_helper.php';

$label = (!isset($inWrapper) || !$inWrapper) ? '' : <<<HTML
                <h3 $labelAttr>
                    Cookies
                </h3>
HTML;
$id = substr($inputId,4,-1);
$name = substr($inputName,11,-1);
$html = <<<HTML
   <div $containerAttr>{$label}
       <input $inputAttr type='hidden' />
       <script>
            var form = document.getElementById('mauticform$formName');
            if (form) {
                form.addEventListener('submit', function() {
                    if(typeof MauticJS !== 'undefined') {
                        var cookie = MauticJS.getCookie('$name');
                        if (cookie) {
                            document.getElementById('$id').value = cookie;
                        }
                    }
                });
            }
       </script>
   </div>
HTML;
echo $html;
