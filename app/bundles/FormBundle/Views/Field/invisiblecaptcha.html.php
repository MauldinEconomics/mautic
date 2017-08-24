<?php
$defaultInputClass = $containerType = 'freehtml';
include __DIR__.'/field_helper.php';

$label = (!isset($inWrapper) || !$inWrapper) ? '' :
    <<<HTML
                <h3 $labelAttr>
                   Invisible Captcha
                </h3>
HTML;

$html = <<<HTML
            <div $containerAttr>{$label}
                <div $inputAttr>
                    {$properties['text']}
                </div>
            </div>
HTML;
echo $html;
