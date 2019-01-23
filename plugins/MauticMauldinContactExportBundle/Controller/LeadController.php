<?php

namespace MauticPlugin\MauticMauldinContactExportBundle\Controller;

use Doctrine\ORM\Query;
use Exporter\Handler;
use Exporter\Source\SourceIteratorInterface;
use Exporter\Writer\CsvWriter;
use Exporter\Writer\XlsWriter;
use Mautic\FormBundle\Controller\FormController;
use Mautic\LeadBundle\Model\LeadModel;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeadController extends FormController
{
    /**
     * Bulk export contacts.
     *
     * @return array|JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function batchExportAction()
    {
        //set some permissions
        $permissions = $this->get('mautic.security')->isGranted(
            [
                'lead:leads:viewown',
                'lead:leads:viewother',
                'lead:leads:create',
                'lead:leads:editown',
                'lead:leads:editother',
                'lead:leads:deleteown',
                'lead:leads:deleteother',
                'lead:batch:export',
            ],
            'RETURN_ARRAY'
        );

        if (!$permissions['lead:batch:export']) {
            return $this->accessDenied();
        }

        if (!$permissions['lead:leads:viewown'] && !$permissions['lead:leads:viewother']) {
            return $this->accessDenied();
        }

        /** @var \MauticPlugin\MauticMauldinCSIBundle\Model\LeadModel $model */
        $model      = $this->getModel('mauldincsi.lead');
        $session    = $this->get('session');
        $search     = $session->get('mautic.lead.filter', '');
        $orderBy    = $session->get('mautic.lead.orderby', 'l.last_active');
        $orderByDir = $session->get('mautic.lead.orderbydir', 'DESC');
        $ids        = $this->request->get('ids');

        $filter     = ['string' => $search, 'force' => ''];
        $translator = $this->get('translator');
        $model->setTranslator($translator);
        $anonymous = $translator->trans('mautic.lead.lead.searchcommand.isanonymous');
        $mine      = $translator->trans('mautic.core.searchcommand.ismine');
        $indexMode = $session->get('mautic.lead.indexmode', 'list');
        $dataType  = $this->request->get('filetype', 'csv');

        if (!empty($ids)) {
            $filter['force'] = [
                [
                    'column' => 'l.id',
                    'expr'   => 'in',
                    'value'  => json_decode($ids, true),
                ],
            ];
        } else {
            if ($indexMode != 'list' || ($indexMode == 'list' && strpos($search, $anonymous) === false)) {
                //remove anonymous leads unless requested to prevent clutter
                $filter['force'] .= " !$anonymous";
            }

            if (!$permissions['lead:leads:viewother']) {
                $filter['force'] .= " $mine";
            }
        }

        $args = [
            'start'          => 0,
            'limit'          => 1000,
            'filter'         => $filter,
            'orderBy'        => $orderBy,
            'orderByDir'     => $orderByDir,
            'withTotalCount' => true,
        ];
        $global_fields = [];

        $toExport = new DataExportIterator($model, $args);

        return $this->exportIteratorAs($toExport, $dataType, 'contacts');
    }

    public function exportIteratorAs($toExport, $type, $filename)
    {
        if (!in_array($type, ['csv', 'xlsx'])) {
            throw new \InvalidArgumentException($this->translator->trans('mautic.error.invalid.export.type', ['%type%' => $type]));
        }

        $dateFormat     = $this->coreParametersHelper->getParameter('date_format_dateonly');
        $dateFormat     = str_replace('--', '-', preg_replace('/[^a-zA-Z]/', '-', $dateFormat));
        $sourceIterator = $toExport;
        $writer         = $type === 'xlsx' ? new XlsWriter('php://output') : new CsvWriter('php://output');
        $contentType    = $type === 'xlsx' ? 'application/vnd.ms-excel' : 'text/csv';
        $filename       = strtolower($filename.'_'.((new \DateTime())->format($dateFormat)).'.'.$type);
        ini_set('max_execution_time', '900');

        return new StreamedResponse(function () use ($sourceIterator, $writer) {
            Handler::create($sourceIterator, $writer)->export();
        }, 200, ['Content-Type' => $contentType, 'Content-Disposition' => sprintf('attachment; filename=%s', $filename)]);
    }
}

class DataExportIterator implements SourceIteratorInterface
{
    private $current  = 0;
    private $subindex = 0;
    private $iterations;
    private $currentData = [];
    private $resultsCallback;
    private $model;
    private $loop;
    private $args;

    public function __construct(LeadModel $model, array $args)
    {
        $this->model                   = $model;
        $this->args                    = $args;
        $this->loop                    = 1;
        $this->args['orderBy']         = 'l.id';
        $this->args['orderByDir']      = 'ASC';
        $this->args['filter']['force'] =
            [
                [
                    'column' => 'l.id',
                    'expr'   => 'gt',
                    'value'  => $this->current,

                ],
            ];

        $this->args['hydration_mode'] = Query::HYDRATE_ARRAY;
        $results                      = $model->getEntities($this->args);

        $count                        = $results['count'];
        $this->args['withTotalCount'] = false;
        $this->current                = array_values(array_slice($results['results'], -1))[0]->getId();
        $this->resultsCallback        = function ($contact) {
            return [
                'id'         => $contact->getId(),
                'email'      => $contact->getEmail(),
                'first_name' => $contact->getFirstname(),
                'last_name'  => $contact->getLastname(),
               ];
        };
        $callback = $this->resultsCallback;
        if (is_callable($this->resultsCallback)) {
            foreach ($results['results'] as $item) {
                $this->currentData[] = $callback($item);
            }
        } else {
            foreach ($results['results'] as $item) {
                $this->currentData[] = (array) $item;
            }
        }
        unset($results);
        $model->getRepository()->clear();
        gc_collect_cycles();

        $this->iterations = ceil($count / $this->args['limit']);
    }

    public function key()
    {
        return $this->current + '-' + $this->subindex;
    }

    public function current()
    {
        if ($this->subindex == count($this->currentData)) {
            $this->currentData = [];

            $this->args['filter'] = [
                'force' => [
                    [
                        'column' => 'l.id',
                        'expr'   => 'gt',
                        'value'  => $this->current,
                    ],
                ],
            ];

            $items         = $this->model->getLeadsPaginated($this->args);
            $this->current = array_values(array_slice($items, -1))[0]->getId();

            $callback = $this->resultsCallback;
            if (is_callable($this->resultsCallback)) {
                foreach ($items as $item) {
                    $this->currentData[] = $callback($item);
                }
            } else {
                foreach ($items as $item) {
                    $this->currentData[] = (array) $item;
                }
            }
            ++$this->loop;
            $this->subindex = 0;
            unset($items);
            $this->model->getRepository()->clear();
            gc_collect_cycles();

            return $this->currentData[$this->subindex];
        } else {
            return $this->currentData[$this->subindex];
        }
    }

    public function valid()
    {
        $valid = $this->loop <= $this->iterations && !($this->loop == $this->iterations && $this->subindex == count($this->currentData));

        return $valid;
    }

    public function next()
    {
        if ($this->subindex == count($this->currentData)) {
        } else {
            ++$this->subindex;
        }
    }

    public function rewind()
    {
    }
}
