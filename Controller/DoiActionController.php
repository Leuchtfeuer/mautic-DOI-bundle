<?php

namespace MauticPlugin\LeuchtfeuerDoiBundle\Controller;

use Mautic\CoreBundle\Controller\AbstractStandardFormController;
use Mautic\FormBundle\Form\Type\ActionType;
use Mautic\FormBundle\Model\FormModel;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiAction;
use MauticPlugin\LeuchtfeuerDoiBundle\Model\FormDoiActionManager;
use MauticPlugin\LeuchtfeuerDoiBundle\Service\FormDoiActionSessionManager;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class DoiActionController extends AbstractStandardFormController
{
    public function newAction(
        Request $request,
        FormDoiActionSessionManager $formDoiActionSessionManager,
        FormModel $formModel): JsonResponse|Response
    {
        $valid   = $cancelled   = false;
        $method  = $request->getMethod();
        $keyId   = null;

        if (Request::METHOD_POST === $method) {
            $formAction = $request->request->all()['formaction'] ?? [];
            $actionType = $formAction['type'] ?? null;
            $formId     = $formAction['formId'] ?? null;
        } else {
            $actionType = $request->query->get('type');
            $formId     = $request->query->get('formId');
            $formAction = [
                'type'   => $actionType,
                'formId' => $formId,
            ];
        }

        // ajax only for form fields
        if (!$actionType
            || !$request->isXmlHttpRequest()
            || !$this->security->isGranted(['form:forms:editown', 'form:forms:editother', 'form:forms:create'], 'MATCH_ONE')
        ) {
            return $this->modalAccessDenied();
        }

        // fire the form builder event
        $customComponents = $formModel->getCustomComponents();
        $form             = $this->formFactory->create(ActionType::class, $formAction, [
            'action'   => $this->generateUrl('mautic_doi_formaction_action', ['objectAction' => 'new']),
            'settings' => $customComponents['actions'][$actionType],
            'formId'   => $formId,
        ]);
        $form->get('formId')->setData($formId);
        $formAction['settings'] = $customComponents['actions'][$actionType];

        // Check for a submitted form and process it
        if (Request::METHOD_POST === $method) {
            if (!$cancelled = $this->isFormCancelled($form)) {
                if ($valid = $this->isFormValid($form)) {
                    // form is valid so process the data
                    $keyId = 'new'.hash('sha1', uniqid((string) mt_rand()));

                    $formData         = $form->getData();
                    $formAction       = array_merge($formAction, $formData);
                    $formAction['id'] = $keyId;
                    if (!isset($formAction['name']) || '' === $formAction['name']) {
                        // set it to the event default
                        $formAction['name'] = $this->translator->trans($formAction['settings']['label']);
                    }
                    $formDoiActionSessionManager->updateActionInSession($formId, $keyId, $formAction);
                }
            }
        }

        $result     = $this->determineViewParamsAndModalClosure($actionType, $cancelled, $valid, $form, $formAction);
        $viewParams = $result['viewParams'];
        $closeModal = $result['closeModal'];

        $passthroughVars = [
            'mauticContent' => 'formDoiAction',
            'route'         => false,
        ];

        if (!is_null($keyId)) {
            // prevent undefined errors
            $entity     = new FormDoiAction();
            $blank      = $entity->convertToArray();
            $formAction = array_merge($blank, $formAction);

            $template                      = '@LeuchtfeuerDoi/Action/_generic.html.twig';
            $passthroughVars['actionId']   = $keyId;
            $passthroughVars['actionHtml'] = $this->renderView($template, [
                'inForm' => true,
                'action' => $formAction,
                'id'     => $keyId,
                'formId' => $formId,
            ]);
        }

        if ($closeModal) {
            // just close the modal
            $passthroughVars['closeModal'] = 1;

            return new JsonResponse($passthroughVars);
        }

        return $this->ajaxAction($request, [
            'contentTemplate' => '@LeuchtfeuerDoi/Builder/'.$viewParams['tmpl'].'.html.twig',
            'viewParameters'  => $viewParams,
            'passthroughVars' => $passthroughVars,
        ]);
    }

    public function editAction(
        Request $request,
        string $objectId,
        FormDoiActionSessionManager $formDoiActionSessionManager,
        FormModel $formModel): JsonResponse|Response
    {
        $method     = $request->getMethod();
        $formaction = $request->request->all()['formaction'] ?? [];
        $formId     = Request::METHOD_POST === $method ? ($formaction['formId'] ?? '') : $request->query->get('formId');
        $actions    = $formDoiActionSessionManager->getActionsFromSession($formId);
        $valid      = $cancelled      = false;
        $formAction = $actions[$objectId] ?? null;
        $keyId      = null;

        if (null === $formAction) {
            return new JsonResponse(null, Response::HTTP_NOT_FOUND);
        }

        $actionType             = $formAction['type'];
        $customComponents       = $formModel->getCustomComponents();
        $formAction['settings'] = $customComponents['actions'][$actionType];

        // ajax only for form fields
        if (!$actionType
            || !$request->isXmlHttpRequest()
            || !$this->security->isGranted(['form:forms:editown', 'form:forms:editother', 'form:forms:create'], 'MATCH_ONE')
        ) {
            return $this->modalAccessDenied();
        }

        $form = $this->formFactory->create(ActionType::class, $formAction, [
            'action'   => $this->generateUrl('mautic_doi_formaction_action', ['objectAction' => 'edit', 'objectId' => $objectId]),
            'settings' => $formAction['settings'],
            'formId'   => $formId,
        ]);
        $form->get('formId')->setData($formId);

        // Check for a submitted form and process it
        if (Request::METHOD_POST === $method) {
            if (!$cancelled = $this->isFormCancelled($form)) {
                if ($valid = $this->isFormValid($form)) {
                    // form is valid so process the data
                    $formData = $form->getData();
                    // overwrite with updated data
                    $formAction = array_merge($actions[$objectId], $formData);
                    if (!isset($formAction['name']) || '' === $formAction['name']) {
                        // set it to the event default
                        $formAction['name'] = $this->translator->trans($formAction['settings']['label']);
                    }
                    $formDoiActionSessionManager->updateActionInSession($formId, $objectId, $formAction);

                    // generate HTML for the field
                    $keyId = $objectId;

                    // take note if this is a submit button or not
                    if ('button' === $actionType) {
                        $submits = $request->getSession()->get('mautic.formactions.submits', []);
                        if ('submit' === $formAction['properties']['type'] && !in_array($keyId, $submits, true)) {
                            // button type updated to submit
                            $submits[] = $keyId;
                            $request->getSession()->set('mautic.formactions.submits', $submits);
                        } elseif ('submit' !== $formAction['properties']['type'] && in_array($keyId, $submits, true)) {
                            // button type updated to something other than submit
                            $key = array_search($keyId, $submits);
                            unset($submits[$key]);
                            $request->getSession()->set('mautic.formactions.submits', $submits);
                        }
                    }
                }
            }
        }

        $result     = $this->determineViewParamsAndModalClosure($actionType, $cancelled, $valid, $form, $formAction);
        $viewParams = $result['viewParams'];
        $closeModal = $result['closeModal'];

        $passthroughVars = [
            'mauticContent' => 'formDoiAction',
            'route'         => false,
        ];

        if (!is_null($keyId)) {
            $passthroughVars['actionId'] = $keyId;

            // prevent undefined errors
            $entity                        = new FormDoiAction();
            $blank                         = $entity->convertToArray();
            $formAction                    = array_merge($blank, $formAction);
            $template                      = '@LeuchtfeuerDoi/Action/_generic.html.twig';
            $passthroughVars['actionHtml'] = $this->renderView($template, [
                'inForm' => true,
                'action' => $formAction,
                'id'     => $keyId,
                'formId' => $formId,
            ]);
        }

        if ($closeModal) {
            // just close the modal
            $passthroughVars['closeModal'] = 1;

            return new JsonResponse($passthroughVars);
        }

        return $this->ajaxAction($request, [
            'contentTemplate' => '@LeuchtfeuerDoi/Builder/'.$viewParams['tmpl'].'.html.twig',
            'viewParameters'  => $viewParams,
            'passthroughVars' => $passthroughVars,
        ]);
    }

    public function deleteAction(Request $request, string $objectId, FormDoiActionSessionManager $formDoiActionSessionManager): JsonResponse
    {
        $formId    = $request->query->get('formId');
        $actions   = $formDoiActionSessionManager->getActionsFromSession($formId);
        $dataArray = [];

        // ajax only for form fields
        if (!$request->isXmlHttpRequest()
            || !$this->security->isGranted(['form:forms:editown', 'form:forms:editother', 'form:forms:create'], 'MATCH_ONE')
        ) {
            throw new AccessDeniedHttpException($this->translator->trans('mautic.core.url.error.401', ['%url%' => $request->getRequestUri()]));
        }

        $formAction = $actions[$objectId] ?? null;
        if (Request::METHOD_POST === $request->getMethod() && null !== $formAction) {
            // Remove the action from the session
            $formDoiActionSessionManager->removeActionFromSession($formId, $objectId);

            // take note if this is a submit button or not
            if ('button' === $formAction['type']) {
                $submits    = $request->getSession()->get('mautic.formactions.submits', []);
                $properties = $formAction['properties'];
                if ('submit' === $properties['type'] && in_array($objectId, $submits, true)) {
                    $key = array_search($objectId, $submits);
                    unset($submits[$key]);
                    $request->getSession()->set('mautic.formactions.submits', $submits);
                }
            }

            $dataArray = [
                'mauticContent' => 'formDoiAction',
                'route'         => false,
            ];
        }

        return new JsonResponse($dataArray);
    }

    protected function getModelName(): string
    {
        return FormDoiActionManager::class;
    }

    /**
     * @param FormInterface<mixed> $form
     * @param array<string, mixed> $formAction
     *
     * @return array<string, mixed>
     */
    private function determineViewParamsAndModalClosure(string $actionType, bool $cancelled, bool $valid, FormInterface $form, array $formAction): array
    {
        $viewParams = ['type' => $actionType];
        $closeModal = false;

        if ($cancelled || $valid) {
            $closeModal = true;
        } else {
            $viewParams['tmpl']         = 'action';
            $viewParams['form']         = $form->createView();
            $viewParams['actionHeader'] = $this->translator->trans($formAction['settings']['label']);

            if (isset($formAction['settings']['formTheme'])) {
                $viewParams['formTheme'] = $formAction['settings']['formTheme'];
            }
        }

        return [
            'viewParams' => $viewParams,
            'closeModal' => $closeModal,
        ];
    }
}
