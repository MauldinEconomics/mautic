<?php
$defaultInputClass = $containerType = 'freehtml';
include __DIR__.'/field_helper.php';
$html = <<<HTML
            <div $containerAttr>
                <div $inputAttr>
                    {$properties['text']}
                </div>
            </div>
HTML;
echo $html;
