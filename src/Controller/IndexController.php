<?php
namespace ZoteroImport\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;
use ZoteroImport\Form\ImportForm;

class IndexController extends AbstractActionController
{
    public function indexAction()
    {
        $form = new ImportForm($this->getServiceLocator());

        if ($this->getRequest()->isPost()) {
            $data = $this->params()->fromPost();
            $form->setData($data);
            if ($form->isValid()) {
                $dispatcher = $this->getServiceLocator()->get('Omeka\JobDispatcher');
                $dispatcher->dispatch('ZoteroImport\Job\Import', $form->getData());
                $this->messenger()->addSuccess('Importing from Zotero');
            } else {
                $this->messenger()->addError('There was an error during validation');
            }
        }

        $view = new ViewModel;
        $view->setVariable('form', $form);
        return $view;
    }
}
