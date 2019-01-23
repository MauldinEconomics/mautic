<?php
// plugins/HelloWorldBundle/Event/ButtonSubscriber.php

namespace MauticPlugin\MauticMauldinContactExportBundle\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomButtonEvent;
use Mautic\CoreBundle\EventListener\CommonSubscriber;
use Mautic\CoreBundle\Templating\Helper\ButtonHelper;

class ButtonSubscriber extends CommonSubscriber
{
    public static function getSubscribedEvents()
    {
        return [
            CoreEvents::VIEW_INJECT_CUSTOM_BUTTONS => ['injectViewButtons', 0],
        ];
    }

    /**
     * @param CustomButtonEvent $event
     */
    public function injectViewButtons(CustomButtonEvent $event)
    {
        if (0 === strpos($event->getRoute(), 'mautic_contact_') && $event->getLocation() == 'page_actions') {
            // Injects a button into the toolbar area for any page with a high priority (displays closer to first)
            $exportRoute = $this->router->generate(
                'mautic_csi_contact_action',
                ['objectAction' => 'batchExport']
            );
            $buttons = $event->getButtons();
            foreach ($buttons as  $button) {
                if ($button['btnText'] == 'Export') {
                    $event->removeButton($button);
                }
            }
            $event->addButton(
                [
                    'attr' => [
                        'href'        => $exportRoute,
                        'data-toggle' => null,
                    ],
                    'btnText'   => $this->translator->trans('mautic.core.export'),
                    'iconClass' => 'fa fa-download',
                ],
                ButtonHelper::LOCATION_PAGE_ACTIONS
            );
        }
    }
}
