<?php

trait ItemControllerTrait
{
    public $workflowData = array();
    public $step;
    public $modelId;
    protected $_actionIsEvaluate = false;

    /**
     * Initializes the controller.
     */
    public function init()
    {
        // Set the model ID if it has been passed as a GET param.
        if (isset($_GET['id'])) {
            $this->modelId = $_GET['id'];
        }
    }

    /**
     * Checks access.
     * @param string $operation
     * @return boolean
     */
    public function checkAccess($operation)
    {
        return Yii::app()->user->checkAccess($this->modelClass . '.' . $operation);
    }

    /**
     * Checks access by model ID.
     * @param integer $id the model ID.
     * @param string $operation the operation.
     * @return boolean
     */
    public function checkModelOperationAccessById($id, $operation)
    {

        return true;

        /** @var CActiveRecord|ItemTrait $model */
        $model = CActiveRecord::model($this->modelClass)->findByPk($id);

        if ($model instanceof CActiveRecord) {
            return Yii::app()->user->checkModelOperationAccess($model, $operation);
        } else {
            throw new CHttpException(404, Yii::t('error', "Failed to check access: the $this->modelClass model with ID $id does not exist."));
        }
    }

    /**
     * Returns the access rules for items.
     * @return array
     */
    public function itemAccessRules()
    {
        $return = array(
            array('allow',
                'actions' => array(
                    'continueAuthoring',
                    'nextRequired',
                ),
                'users' => array('*'),
                'expression' => function () {
                        return $this->checkModelOperationAccessById($this->modelId, 'View');
                    },
            ),
            array(
                'allow',
                'actions' => array(
                    'cancel',
                ),
                'users' => array('*'),
            ),
            array('allow',
                'actions' => array(
                    'view',
                ),
                'expression' => function () {
                        return Yii::app()->user->checkAccess('View');
                    },
            ),
            array('allow',
                'actions' => array(
                    'browse',
                ),
                'expression' => function () {
                        return Yii::app()->user->checkAccess('Browse');
                    },
            ),
            array('allow',
                'actions' => array(
                    'add',
                ),
                'expression' => function () {
                        return $this->checkAccess('Add');
                    },
            ),
            array('allow',
                'actions' => array(
                    'draft',
                    'saveDraft',
                ),
                'expression' => function () {
                        return $this->checkModelOperationAccessById($this->modelId, 'Edit');
                    },
            ),
            array('allow',
                'actions' => array(
                    'addEdges',
                    'deleteEdge',
                ),
                'expression' => function () {
                        return $this->checkAccess('Edit');
                    },
            ),
            array('allow',
                'actions' => array(
                    'prepareForReview',
                    'submitForReview',
                ),
                'expression' => function () {
                        return $this->checkAccess('PrepareForReview');
                    },
            ),
            array('allow',
                'actions' => array(
                    'evaluate',
                ),
                'expression' => function () {
                        return $this->checkModelOperationAccessById($this->modelId, 'Evaluate');
                    },
            ),
            array('allow',
                'actions' => array(
                    'prepareForPublishing',
                    'submitForPublishing',
                ),
                'expression' => function () {
                        return $this->checkModelOperationAccessById($this->modelId, 'PrepareForPublishing');
                    },
            ),
            array('allow',
                'actions' => array(
                    'proofread',
                ),
                'expression' => function () {
                        return $this->checkModelOperationAccessById($this->modelId, 'Proofread');
                    },
            ),
            array('allow',
                'actions' => array(
                    'preview',
                ),
                'expression' => function () {
                        return $this->checkModelOperationAccessById($this->modelId, 'Preview');
                    },
            ),
            array('allow',
                'actions' => array(
                    'translate',
                    'translationOverview',
                ),
                'expression' => function () {
                        return $this->checkModelOperationAccessById($this->modelId, 'Translate');
                    },
            ),
            array('allow',
                'actions' => array(
                    'publish',
                    'unpublish',
                ),
                'expression' => function () {
                        return $this->checkModelOperationAccessById($this->modelId, 'Publish');
                    },
            ),
            array('allow',
                'actions' => array(
                    'edit',
                    'continueAuthoring',
                ),
                'expression' => function () {
                        return $this->checkModelOperationAccessById($this->modelId, 'Edit');
                    },
            ),
            array('allow',
                'actions' => array(
                    'addToGroup',
                    'removeFromGroup',
                ),
                'expression' => function () {
                        return $this->checkModelOperationAccessById($_GET['modelId'], 'ChangeGroup');
                    },
            ),
            array('allow',
                'actions' => array(
                    'clone',
                ),
                'expression' => function () {
                        return $this->checkModelOperationAccessById($this->modelId, 'Clone');
                    },
            ),
            array('allow',
                'actions' => array(
                    'remove',
                ),
                'expression' => function () {
                        return $this->checkModelOperationAccessById($this->modelId, 'Remove');
                    },
            ),
        );

        return $return;
    }

    /**
     * Continues the authoring process.
     * @param integer $id the model ID.
     */
    public function actionContinueAuthoring($id)
    {
        /** @var ActiveRecord|ItemTrait $model */
        $model = $this->loadModel($id);
        $step = $model->firstFlowStep();

        if (true || Yii::app()->user->checkModelOperationAccess($model, 'Edit')) {
            $this->redirect(array('edit', 'id' => $model->id, 'step' => $step));
        } else {
            $this->redirect('/' . lcfirst(get_class($model)) . '/browse'); // TODO: Clean up route.
        }
    }

    protected function nextFlowStep($prefix, $item)
    {
        foreach ($item->flowSteps() as $step => $options) {
            if ($item->calculateValidationProgress($prefix . 'step_' . $step) < 100) {
                return $step;
            }
            $item->clearErrors();
        }
        return null;
    }

    /**
     * Checks if the current action uses the edit workflow (but not the translation workflow).
     * @return boolean
     */
    public function actionUsesEditWorkflow()
    {
        $editActions = array(
            'edit',
            'prepareForPublishing',
            'prepareForReview',
        );

        return in_array($this->action->id, $editActions);
    }

    /**
     * Runs operations before all ItemController actions.
     * @param CAction $action
     * @return boolean
     */
    public function beforeItemControllerAction($action)
    {
        $translateInto = Yii::app()->request->getParam('translateInto');

        // Redirect to next required step
        if (($action->id === 'prepareForReview' || $action->id === 'prepareForPublishing') && isset($_POST['next-required-url'])) {
            $this->redirect($_POST['next-required-url']);
        }

        // Set translation and edit workflow returnUrls
        if (in_array($action->id, array('translate', 'edit'))) {
            $this->setWorkflowReturnUrl();
        }

        // Disallow user to translate items into languages other than those listed in his profile
        if ($action->id === 'translate' && !Yii::app()->user->canTranslateInto($translateInto)) {
            throw new CHttpException(
                403,
                Yii::t('app', "You are not permitted to translate into: $translateInto")
            );
        }

        return true;
    }

    /**
     * Sets the returnUrl when navigating between workflow steps.
     */
    public function setWorkflowReturnUrl()
    {
        $returnUrl = Yii::app()->request->getParam('returnUrl');

        if (isset($returnUrl)) {
            Yii::app()->user->setReturnUrl($returnUrl);
        }
    }

    /**
     * Returns the target translation language. Defaults to null if undefined.
     * @return string|null
     */
    public function getTranslationLanguage()
    {
        return isset($this->workflowData['translateInto'])
            ? $this->workflowData['translateInto']
            : null;
    }

    /**
     * TODO: Move away from controller to model or helper
     *
     * Returns a CDbCriteria which shows the models in testable-state or higher for translators.
     *
     * @param $modelTbl
     * @param array $defaultCriteria the criteria to return if user has Administrator-role or is not a translator
     * @return array|\CDbCriteria
     */
    /*
    public function getTranslatorCriteria($modelTbl, $defaultCriteria = array())
    {
        // Administrators should see everything so return early
        if (Yii::app()->user->checkAccess('Administrator')) {
            return $defaultCriteria;
        }

        $criteria = $defaultCriteria;

        return $criteria;

        // TODO: Make group-dependent

        // Translators should only see items which are in testable mode or higher
        if (Yii::app()->user->checkAccess('Translate')) {
            $criteria = new CDbCriteria();

            $qaStateTbl = $modelTbl . '_qa_state'; // model_table_qa_state
            $qaStateForeignId = $qaStateTbl . '_id'; // model_table_qa_state_id

            $criteria->join = sprintf('INNER JOIN %s qs ON %s = qs.id', $qaStateTbl, $qaStateForeignId);
            $criteria->addInCondition('status', array('reviewable', 'publishable'));
        }

        return $criteria;
    }
    */

    /**
     * Renders the browse page.
     */
    public function actionBrowse()
    {
        $model = new $this->modelClass('search');
        $model->unsetAttributes();

        if (isset($_GET[$this->modelClass])) {
            $model->attributes = $_GET[$this->modelClass];
        }

        $dataProvider = $model->search();

        $this->populateWorkflowData($model, null, Yii::t('app', 'Browse'));

        /** @var Controller $this */
        $this->buildBreadcrumbs(array(
            Yii::t('app', $model->modelLabel, 2) => array('browse'),
            Yii::t('app', 'Browse'),
        ));

        $this->render(
            'vendor.neam.yii-workflow-ui.themes.simplicity.views._item.browse',
            array(
                'model' => $model,
                'dataProvider' => $dataProvider,
            )
        );
    }

    public function actionAdd()
    {
        if (isset($_POST["from_node_id"]) && is_numeric($_POST["from_node_id"])) {
            // Check if it's the (this) type or specified:
            if (isset($_POST["toType"])) {
                $item = new $_POST["toType"]();
            } else {
                $item = new $this->modelClass();
            }
            // Set title, if specified:
            if (isset($_POST["newitemtitle"])) {
                try {
                    $item->_title = $_POST["newitemtitle"];
                } catch (Exception $e) {
                    // no title for this type
                }
            }
            // Save!
            if (!$item->save()) {
                throw new SaveException($item);
            }
            $behaviors = $item->behaviors();
            if (isset($behaviors['yii-qa-state'])) {
                $item->refreshQaState();
            }
            $message = "{$this->modelClass} Added";

            // Add edge if specified:
            if (isset($_POST["addEdge"]) && $_POST["addEdge"] == "true" && $_POST["from_node_id"] && $_POST["relation"]) {
                $to_node_id = $item->node_id;
                $from_node_id = $_POST["from_node_id"];
                $relation = $_POST["relation"];
                $this->addEdge($from_node_id, $to_node_id, $relation);
            }
            // Return:
            if (isset($_POST["returnUrl"])) {
                $this->redirect($_POST['returnUrl']);
                exit;
            } else {
                header("Content-type: application/json");
                $result = new StdClass();
                $result->id = $item->id;
                $result->node_id = $item->node_id;
                $result->title = $item->itemLabel;
                echo json_encode($result);
                exit;
            }
        }

        /** @var ActiveRecord|ItemTrait|QaStateBehavior $item */
        $item = new $this->modelClass();

        if (isset($_POST["newitemtitle"])) {
            $item->_title = $_POST["newitemtitle"];
        }

        if (!$item->save()) {
            throw new SaveException($item);
        }

        if (isset($behaviors['yii-qa-state'])) {
            $item->refreshQaState();
        }

        $message = "{$this->modelClass} Added";

        // If fromId is set, we assume this is a new Item which will be related:
        Yii::app()->user->setFlash('success', $message);
        $this->redirect(array('continueAuthoring', 'id' => $item->id));
    }

    public function actionAddEdges()
    {
        if (isset($_POST[$this->modelClass]["fromId"]) && isset($_POST[$this->modelClass]["edges_to_add"]) && isset($_POST[$this->modelClass]["relation"])) {
            $this->addEdges(
                $_POST[$this->modelClass]["fromId"],
                $_POST[$this->modelClass]["edges_to_add"],
                $_POST[$this->modelClass]["relation"]
            );
        }
        exit;
    }

    public function actionDeleteEdge()
    {
        $from = $_GET["from"];
        $to = $_GET["to"];
        $relation = $_GET["relation"];
        $del = Edge::model()->deleteAll(
            'from_node_id=:from_node_id AND to_node_id=:to_node_id AND relation=:relation',
            array(
                ':from_node_id' => $from,
                ':to_node_id' => $to,
                ':relation' => $relation,
            )
        );
        if ($del) {
            $message = "Relation deleted";
        } else {
            $message = "Unable to delete relation";
        }
        if (isset($_GET['returnUrl'])) {
            $delimiter = (strpos($_GET['returnUrl'], "?") !== false) ? "&" : "?";
            $this->redirect($_GET['returnUrl'] . $delimiter . "message=$message");
        }
    }

    public function actionDraft($step, $id)
    {
        $this->scenario = "draft-step_$step";
        $model = $this->loadModel($id);
        $model->scenario = $this->scenario;
        $this->performAjaxValidation($model);
        $this->saveAndContinueOnSuccess($model);
        $stepCaptions = $model->flowStepCaptions();
        $this->render('vendor.neam.yii-workflow-ui.themes.simplicity.views._item.edit', array('model' => $model, 'step' => $step, 'stepCaption' => $stepCaptions[$step]));
    }

    /**
     * Prepare for testing workflow
     * @param $step
     * @param $id
     */
    public function actionPrepareForReview($step, $id)
    {
        $this->scenario = "reviewable-step_$step";
        $model = $this->loadModel($id);
        $model->scenario = $this->scenario;
        $this->performAjaxValidation($model);
        $this->saveAndContinueOnSuccess($model);
        $this->populateWorkflowData($model, 'reviewable', Yii::t('app', 'Prepare for Review'));
        $stepCaptions = $model->flowStepCaptions();

        /** @var Controller|ItemController $this */
        $this->buildBreadcrumbs($this->itemBreadcrumbs($model));

        $this->render(
            'vendor.neam.yii-workflow-ui.themes.simplicity.views._item.edit',
            array(
                'model' => $model,
                'step' => $step,
                'stepCaption' => $stepCaptions[$step],
            )
        );
    }

    /**
     * TODO: this action needs to add the item to relevant skill groups as well
     * @param $id
     * @throws SaveException
     */
    public function actionSubmitForReview($id)
    {
        $model = $this->loadModel($id);
        $qaState = $model->qaState();

        // save state change
        $qaState->allow_review = 1;
        if (!$qaState->save()) {
            throw new SaveException($qaState);
        }
        $model->refreshQaState();

        // redirect
        if (isset($_GET['returnUrl'])) {
            $this->redirect($_GET['returnUrl']);
        } else {
            $this->redirect(array('continueAuthoring', 'id' => $model->id));
        }

    }

    /**
     * Evaluate workflow
     * @param $id
     * @param $step
     */
    public function actionEvaluate($id, $step)
    {
        $this->scenario = "evaluate-step_$step";
        $model = $this->loadModel($id);
        $model->scenario = $this->scenario;
        $this->performAjaxValidation($model);
        $this->saveAndContinueOnSuccess($model);
        $this->populateWorkflowData($model, "evaluate", Yii::t('app', 'Evaluate'));
        $stepCaptions = $model->flowStepCaptions();
        $this->_actionIsEvaluate = true;

        /** @var Controller|ItemController $this */
        $this->buildBreadcrumbs($this->itemBreadcrumbs($model));

        $this->render(
            'vendor.neam.yii-workflow-ui.themes.simplicity.views._item.evaluate',
            array(
                'model' => $model,
                'step' => $step,
                'stepCaption' => $stepCaptions[$step],
            )
        );
    }

    /**
     * Prepare for publishing workflow
     * @param $step
     * @param $id
     */
    public function actionPrepareForPublishing($step, $id)
    {
        $this->scenario = "publishable-step_$step";
        $model = $this->loadModel($id);
        $model->scenario = $this->scenario;
        $this->performAjaxValidation($model);
        $this->saveAndContinueOnSuccess($model);
        $this->populateWorkflowData($model, 'publishable', Yii::t('app', 'Prepare for Publishing'));
        $stepCaptions = $model->flowStepCaptions();

        /** @var Controller|ItemController $this */
        $this->buildBreadcrumbs($this->itemBreadcrumbs($model));

        $this->render(
            'vendor.neam.yii-workflow-ui.themes.simplicity.views._item.edit',
            array(
                'model' => $model,
                'step' => $step,
                'stepCaption' => $stepCaptions[$step],
            )
        );
    }

    public function actionSubmitForPublishing($id)
    {
        $model = $this->loadModel($id);
        $qaState = $model->qaState();

        // save state change
        $qaState->allow_publish = 1;
        if (!$qaState->save()) {
            throw new SaveException($qaState);
        }
        $model->refreshQaState();

        // redirect
        if (isset($_GET['returnUrl'])) {
            $this->redirect($_GET['returnUrl']);
        } else {
            $this->redirect(array('continueAuthoring', 'id' => $model->id));
        }

    }

    /**
     * Renders the preview page.
     * @param int $id model ID.
     */
    public function actionPreview($id)
    {
        $model = $this->loadModel($id);

        /** @var Controller|ItemController $this */
        $this->buildBreadcrumbs($this->itemBreadcrumbs($model));

        $this->render('vendor.neam.yii-workflow-ui.themes.simplicity.views._item.preview', array('model' => $model, 'workflowCaption' => Yii::t('app', 'Preview')));
    }

    public function actionProofread($id)
    {
        $model = $this->loadModel($id);
        $this->render('vendor.neam.yii-workflow-ui.themes.simplicity.views._item.proofread', array('model' => $model));
    }

    /**
     * Publishes an item.
     * @param int $id
     * @throws CException
     * @throws CHttpException
     * @throws SaveException
     */
    public function actionPublish($id)
    {
        /** @var ActiveRecord|ItemTrait|QaStateBehavior $model */
        /** @var Controller $this */

        // TODO: Save changeset.

        $model = $this->loadModel($id);

        if (!$model->qaStateBehavior()->validStatus('publishable')) {
            throw new CException('This item does not validate for the publishable status.');
        }

        $model->changeStatus('public');
        $model->makeNodeHasGroupVisible();

        $this->redirect(array('browse'));
    }

    /**
     * Unpublishes an item.
     * @param int $id
     * @throws CHttpException
     */
    public function actionUnpublish($id)
    {
        $permissionAttributes = array(
            'account_id' => Yii::app()->user->id,
            'group_id' => PermissionHelper::groupNameToId('GapminderInternal'),
            'role_id' => PermissionHelper::roleNameToId('Group Publisher'),
        );

        if (PermissionHelper::groupHasAccount($permissionAttributes)) {
            $model = $this->loadModel($id);
            $model->refreshQaState();
            $model->makeNodeHasGroupHidden();
        } else {
            throw new CHttpException(403, Yii::t('error', 'You do not have permission to unpublish items.'));
        }

        $this->redirect(array('browse'));
    }

    /**
     * Aborts a workflow.
     * @param int $id model ID.
     */
    public function actionCancel($id)
    {
        /** @var ActiveRecord|ItemTrait|QaStateBehavior $model */
        /** @var Controller $this */

        $model = $this->loadModel($id);
        $step = $model->firstFlowStep();

        if (Yii::app()->user->checkModelOperationAccess($model, 'Edit')) {
            $this->redirect(array(
                'edit',
                'id' => $model->id,
                'step' => $step,
            ));
        } else {
            $this->redirect(array(
                'view',
                'id' => $model->id,
            ));
        }
    }

    /**
     * "Not-in-a-workflow"-workflow
     * @param $step
     * @param $id
     */
    public function actionEdit($step, $id, $returnUrl = null)
    {
        $this->step = $step;
        $this->scenario = "temporary-step_$step";

        $model = $this->loadModel($id);

        $model->scenario = $this->scenario;

        $behaviors = $model->behaviors();
        if (isset($behaviors['yii-relational-graph-db'])) {
            if (Yii::app()->request->getPost($this->modelClass, false)) {
                $this->handleEdges($model);
            }
        }

        $this->performAjaxValidation($model);
        $this->saveAndContinueOnSuccess($model);

        $this->populateWorkflowData($model, null, Yii::t('app', 'Edit'));
        $stepCaptions = $model->flowStepCaptions();

        $this->setPageTitle(array(
            Yii::t('model', $this->modelClass),
            $this->workflowData['caption'],
        ));

        $requiredCounts = $this->getRequiredCounts($id);

        /** @var Controller|ItemController $this */
        $this->buildBreadcrumbs($this->itemBreadcrumbs($model));

        $this->render('vendor.neam.yii-workflow-ui.themes.simplicity.views._item.edit', array(
            'model' => $model,
            'step' => $step,
            'stepCaption' => $stepCaptions[$step],
            'requiredCounts' => $requiredCounts,
        ));
    }

    /**
     * @param $model ActiveRecord
     */
    protected function handleEdges($model)
    {
        $post = Yii::app()->request->getPost($this->modelClass, array());

        $node = $model->node();

        // array of models relation-names
        $relationNames = array_keys($model->relations());

        // Relations considered safe. Note: set the relation as safe in the model-rules (if it is not already)!
        $allowedRelations = array_filter(
        // attribute-names posted from form
            array_keys($post),
            // add to list if the attribute is a safe relation
            function ($attribute) use ($model, $relationNames) {
                return in_array($attribute, $relationNames) && $model->isAttributeSafe($attribute);
            }
        );

        foreach ($allowedRelations as $relationName) {

            $futureOutEdges = $post[$relationName];

            $isFutureOutEdgesEmpty = empty($futureOutEdges);

            // Delete all outEdges for the relation if none is present in
            // form and the model has some outEdges (ie user removed edges)
            if ($isFutureOutEdgesEmpty && count($model->{$relationName}) > 0) {

                Edge::model()->deleteAllByAttributes(array(
                    'from_node_id' => $node->id,
                    'relation' => $relationName,
                ));
            }

            if ($isFutureOutEdgesEmpty) {
                $futureOutEdges = array();
            }

            $this->deleteEdgeDiff($model, $futureOutEdges, $relationName);

            // TODO: fetch weights properly when they are implemented in form

            $weight = 0;
            foreach ($futureOutEdges as $toNodeId) {
                $this->addEdge($node->id, $toNodeId, $relationName, $weight++);
            }

        }
    }

    /**
     * Deletes the edges which are present but not in future-edges
     *
     * @param $model ActiveRecord
     * @param $futureEdges array
     * @param $relationName string
     * @return int number of edges deleted
     */
    protected function deleteEdgeDiff(ActiveRecord $model, array $futureEdges, $relationName)
    {
        // {1,2,3}
        $currentOutEdges = $model->getRelatedModelColumnValues($relationName, 'id');

        // {1,2,3} complement {2,3,4} = {1}
        $edgesToDelete = array_diff($currentOutEdges, $futureEdges);

        $criteria = new CDbCriteria();
        $criteria->addCondition('from_node_id = :from');
        $criteria->addCondition('relation = :relation');
        $criteria->addInCondition('to_node_id', $edgesToDelete);
        $criteria->params[':from'] = $model->node()->id;
        $criteria->params[':relation'] = $relationName;

        return Edge::model()->deleteAll($criteria);
    }

    private function addEdge($fromNodeId, $toNodeId, $relation, $weight = null)
    {
        $edge = Edge::model()->findByAttributes(array(
            'from_node_id' => $fromNodeId,
            'to_node_id' => $toNodeId,
            'relation' => $relation,
        ));

        // Nothing has changed
        if ($edge !== null && $weight === null) {
            return;
        }

        if ($edge === null) {
            $edge = new Edge();
        }

        $edge->from_node_id = $fromNodeId;
        $edge->to_node_id = $toNodeId;
        $edge->relation = $relation;

        if ($weight !== null) {
            $edge->weight = $weight;
        }

        if (!$edge->save()) {
            throw new SaveException($edge);
        }
        return true;
    }

    /**
     * Adds an item to a group.
     * @param integer $nodeId
     * @param integer $modelId
     * @param string $group
     * @param string $returnUrl
     */
    public function actionAddToGroup($nodeId, $modelId, $group, $returnUrl)
    {
        PermissionHelper::addNodeToGroup($nodeId, $group);
        $this->redirect($returnUrl);
    }

    /**
     * Removes an item from a group.
     * @param integer $nodeId
     * @param integer $modelId
     * @param string $group
     * @param string $returnUrl
     */
    public function actionRemoveFromGroup($nodeId, $modelId, $group, $returnUrl)
    {
        PermissionHelper::removeNodeFromGroup($nodeId, $group);
        $this->redirect($returnUrl);
    }

    /**
     * Returns the controller and action CSS classes for the current controller and action.
     * @param ActiveRecord $model the model.
     * @return string the CSS classes.
     */
    public function getCssClasses($model)
    {
        // todo: use TbHtml::addCssClass instead
        $classes = array();
        $classes[] = 'item-controller';
        if ($model instanceof ActiveRecord) {
            $classes[] = strtolower(get_class($model)) . '-controller';
        }
        $classes[] = strtolower($this->action->id) . '-action';
        return implode(' ', $classes);
    }

    /**
     * Returns the total and remaining number of required attributes.
     * @param integer $id the model ID.
     * @return array
     */
    public function getRequiredCounts($id)
    {
        $model = $this->loadModel($id);
        $model->scenario = $this->scenario;
        $_POST = $this->fixPostFromGrid($_POST);

        $requiredCount = array(
            'total' => 0,
            'remaining' => 0,
        );

        if (isset($_POST[$this->modelClass])) {
            $requiredValidCount = 0;

            foreach ($_POST[$this->modelClass] as $attribute => $value) {
                $validators = $model->getValidators($attribute);

                foreach ($validators as $validator) {
                    if ($validator instanceof CRequiredValidator) {
                        $requiredCount['total'] += 1;

                        $model->{$attribute} = $_POST[$this->modelClass][$attribute];

                        if ($model->validate(array($attribute))) {
                            $requiredValidCount += 1;
                        }
                    }
                }
            }

            $requiredCount['remaining'] = $requiredCount['total'] - $requiredValidCount;
        }

        return $requiredCount;
    }

    public function actionClone($id)
    {
        $model = $this->loadModel($id);

        // clone attributes into a new object
        $attributes = $model->attributes;
        unset($attributes['id']);
        $clone = $model->populateRecord($attributes);

        // reset qa states
        $behaviors = $model->behaviors();

        $qaStateAttribute = $model->getQaStateAttribute();

        if (isset($behaviors['i18n-columns']['translationAttributes']) && in_array($qaStateAttribute, $behaviors['i18n-columns']['translationAttributes'])) {
            foreach (LanguageHelper::getCodes() as $lang) {
                $translatedAttribute = $qaStateAttribute . "_" . $lang;
                $clone->$translatedAttribute = null;
            }
        } else {
            $clone->$qaStateAttribute = null;
        }

        // mark ancestry
        $clone->cloned_from_id = $model->id;

        // increment version number
        $clone->version = is_null($model->version) ? 2 : $model->version + 1;

        // start transaction
        $transaction = Yii::app()->db->beginTransaction();

        try {

            // save as new object
            $clone->isNewRecord = true;
            if (!$clone->save()) {
                throw new SaveException($clone);
            }

            $relations = $model->relations();

            // clone relations part 1 - we want the cloned object to be related to the same items - outNodes
            // however we do not want other objects to relate to the cloned object, so we skip inNodes
            $cloneNode = $clone->node();
            foreach ($model->outNodes as $outNode) {
                $edge = new Edge;
                $edge->from_node_id = $cloneNode->id;
                $edge->to_node_id = $outNode->id;
                if (!$edge->save()) {
                    throw new SaveException($edge);
                }
            }
            unset($relations['outEdges']);
            unset($relations['inEdges']);
            unset($relations['outNodes']);
            unset($relations['inNodes']);

            // clone relations part 2
            foreach ($relations as $name => $relation) {

                switch ($relation[0]) {
                    case "CBelongsToRelation";
                        // always ignoring on clone since we are doing shallow copies
                        continue;
                        break;
                    case "CHasManyRelation";
                        // for now ignoring until knowing better or worse
                        continue;
                        break;
                    default:
                        throw new CException("Unsupported relation: " . print_r($relation, true));
                }

            }

            // commit transaction
            $transaction->commit();

        } catch (Exception $e) {
            $clone->addError('id', $e->getMessage());
            $transaction->rollback();
        }

        $this->redirect(array('continueAuthoring', 'id' => $clone->id));
    }

    public function actionRemove($id)
    {
        $model = $this->saveAndContinueOnSuccess($id);
        $this->render('vendor.neam.yii-workflow-ui.themes.simplicity.views._item.remove', array('model' => $model));
    }

    public function actionReplace($id)
    {
        $model = $this->saveAndContinueOnSuccess($id);
        $this->render('vendor.neam.yii-workflow-ui.themes.simplicity.views._item.replace', array('model' => $model));
    }

    /**
     * Renders the translation overview page.
     * @param integer $id the item ID.
     */
    public function actionTranslationOverview($id)
    {
        $model = $this->loadModel($id);
        $model->scenario = $this->scenario;

        /** @var ItemController|Controller $this */
        $this->requireProfileLanguages();

        $this->populateWorkflowData($model, null, Yii::t('app', ''));

        /** @var Controller|ItemController $this */
        $this->buildBreadcrumbs($this->itemBreadcrumbs($model));

        $this->render(
            'vendor.neam.yii-workflow-ui.themes.simplicity.views._item.translation-overview',
            array(
                'model' => $model,
            )
        );
    }

    /**
     * Initiates the translation workflow.
     * @param integer $id the item ID.
     * @param string $step the step identifier.
     * @param string $translateInto the target language code.
     * @throws CHttpException
     */
    public function actionTranslate($id, $step, $translateInto, $returnUrl = null)
    {
        $this->step = $step;

        $this->scenario = "into_$translateInto-step_$step";
        $model = $this->loadModel($id);
        $model->scenario = $this->scenario;
        $this->performAjaxValidation($model);
        $this->saveAndContinueOnSuccess($model);
        $this->populateWorkflowData($model, 'translate_into_' . $translateInto, Yii::t('app', 'Translate into {translateIntoLanguage}', array(
            '{translateIntoLanguage}' => LanguageHelper::getName($translateInto),
        )), $translateInto);
        $stepCaptions = $model->flowStepCaptions();

        /** @var Controller|ItemController $this */
        $this->buildBreadcrumbs($this->itemBreadcrumbs($model));

        $this->render(
            'vendor.neam.yii-workflow-ui.themes.simplicity.views._item.edit',
            array(
                'model' => $model,
                'step' => $step,
                'stepCaption' => $stepCaptions[$step],
            )
        );
    }

    /**
     * Returns actions based on the current qa state TODO: and access rules
     * together with progress calculations and whether or not the action is available yet or not
     * @param ActiveRecord|ItemTrait|QaStateBehavior $item
     * @param string $validationScenario
     * @param string $caption
     * @param string|null $translateInto
     * @return array
     */
    public function populateWorkflowData($item, $validationScenario, $caption, $translateInto = null)
    {
        $this->workflowData["action"] = $this->action->id;
        $this->workflowData["caption"] = $caption;
        $this->workflowData["validationScenario"] = $validationScenario;
        $this->workflowData["translateInto"] = $translateInto;

        $stepActions = array();
        $flagTriggerActions = array();

        //var_dump($item->qaState()->attributes);

        // The overarching workflow progress
        $workflowProgress = !is_null($validationScenario) ? $item->calculateValidationProgress($validationScenario) : null;

        /*
        if ($this->action->id == "draft") {
            $flagTriggerActions[] = array(
                'label' => Yii::t('app', 'Save Draft'),
                'requiredProgress' => $item->calculateValidationProgress('draft'),
                'action' => 'saveDraft'
            );

            $editAction = "draft";

        } else
        */
        if ($this->action->id == "prepareForReview") {
            $flagTriggerActions[] = array(
                'label' => Yii::t('app', 'Submit for review'),
                'requiredProgress' => $item->calculateValidationProgress('reviewable'),
                'action' => 'submitForReview'
            );

            $editAction = "prepareForReview";

        } elseif ($this->action->id == "prepareForPublishing") {
            $flagTriggerActions[] = array(
                'label' => Yii::t('app', 'Submit for publishing'),
                'requiredProgress' => $item->calculateValidationProgress('publishable'),
                'action' => 'submitForPublishing'
            );

            $editAction = "prepareForPublishing";

        } elseif ($this->action->id == "translate") {

            $editAction = "translate";

        } else {

            $editAction = "edit";

        }

        // When in edit-views we consider ourselves outside any active workflow
        if ($this->action->id == "edit") {
            $editAction = "edit";
        }

        $stepCaptions = $item->flowStepCaptions();
        foreach ($item->flowSteps() as $step => $fields) {

            if ($this->action->id == "translate" && $translateInto !== null) {
                if (!$this->isStepTranslatable($item, $fields)) {
                    continue;
                }
                //$stepProgress = $item->calculateValidationProgress('into_' . $translateInto . "-step_" . $step);
                $stepProgress = null; // Inactivated since we are not using step progress
            } elseif ($this->action->id == "edit") {
                //$stepProgress = $item->calculateValidationProgress("step_$step-total_progress");
                $stepProgress = null; // Inactivated since we are not using step progress
            } else {
                //$stepProgress = $item->calculateValidationProgress("step_" . $step);
                $stepProgress = null; // Inactivated since we are not using step progress
            }

            $stepActions[] = array(
                "step" => $step,
                "editAction" => $editAction,
                "model" => $item,
                "fields" => $fields,
                "caption" => $stepCaptions[$step],
                "progress" => $stepProgress,
                "translateInto" => $translateInto,
                "workflowProgress" => $workflowProgress,
            );
        }

        $this->workflowData["stepActions"] = $stepActions;
        $this->workflowData["flagTriggerActions"] = $flagTriggerActions;

    }

    /**
     * @param $item
     * @param $fields
     * @return bool
     */
    public function isStepTranslatable($item, $fields)
    {
        $currentlyTranslatableAttributes = $item->getCurrentlyTranslatableAttributes();

        $numTranslatableAttributes = count($fields);
        foreach ($fields as $field) {
            $sourceLanguageContentAttribute = str_replace('_' . $item->source_language, '', $field);
            if (!in_array($sourceLanguageContentAttribute, $currentlyTranslatableAttributes)) {
                $numTranslatableAttributes--;
            }
        }

        return $numTranslatableAttributes > 0;
    }

    // $fromid = [item] id, $toid = [node] id ! important
    private function addEdges($fromid, $toids, $relation)
    {
        $from_model = $this->loadModel($fromid);
        $from_node_id = $from_model->node()->id;

        foreach ($toids as $to_node_id) {
            $this->addEdge($from_node_id, $to_node_id, $relation);
        }
    }

    // Asks $_POST if a value isn't part of "Model"
    // If it ends with _c0, we suppose it's from a grid input, and put it into $_POST[$this->modelClass]
    // Returns $_POST with fixed values
    private function fixPostFromGrid($post)
    {
        $return_array = array();
        foreach ($post as $key => $p) {
            if (strrpos($key, '_c0') !== false) {
                $newkey = substr($key, 0, -3);
                $return_array[$this->modelClass][$newkey] = $p;
            } else {
                $return_array[$key] = $p;
            }
        }
        return $return_array;
    }

    /**
     * Saves a model and
     * @param $model
     * @return mixed
     */
    protected function saveAndContinueOnSuccess($model)
    {
        $_POST = $this->fixPostFromGrid($_POST);

        // Log for dev purposes
        /** @var ActiveRecord|ItemTrait $model */
        Yii::log("model->scenario: " . print_r($model->scenario, true), "flow", __METHOD__);
        Yii::log("_POST: " . print_r($_POST, true), "flow", __METHOD__);

        if (isset($_POST[$this->modelClass])) {
            $model->attributes = $_POST[$this->modelClass];
            $model->saveAppropriately();
        } elseif (isset($_GET[$this->modelClass])) {
            $model->attributes = $_GET[$this->modelClass];
        }

        if ($model->hasErrors()) {
            // TODO: Figure out why error data needs to be restructured like this.
            $errors = array_map(
                function ($v) {
                    return join(', ', $v);
                },
                $model->getErrors()
            );

            // TODO: Figure out why the id attribute contains array data as a string.
            if (isset($errors['id'])) {
                unset($errors['id']);
            }

            Yii::app()->user->setFlash(TbHtml::ALERT_COLOR_DANGER, Html::renderValidationErrors($errors));
        }

        if ($model->hasErrors() || empty($_POST)) {

            return $model;

        } else {
            $this->setBackToTranslationUrl();

            // redirect
            if (isset(Yii::app()->user->returnUrl)) {
                $this->redirect(Yii::app()->user->returnUrl);
            } else if (isset($_REQUEST['returnUrl'])) {
                $this->redirect($_REQUEST['returnUrl']);
            } else if (isset($_POST['save-changes'])) {
                $this->redirect($_REQUEST['form-url']);
            } else if (isset($_POST['next-required'])) {
                $this->redirect($_REQUEST['next-required-url']);
            } else {
                $this->actionCancel($model->id);
            }
        }
    }

    /**
     * Checks if the back to translation button should be displayed.
     * @return bool
     */
    public function showBackToTranslationButton()
    {
        return Yii::app()->user->isTranslator && $this->getBackToTranslationUrl() !== '#';
    }

    /**
     * Returns the URL to the previous translation workflow step.
     * @return string
     */
    public function getBackToTranslationUrl()
    {
        return isset(Yii::app()->session['backToTranslationUrl'])
            ? Yii::app()->session['backToTranslationUrl']
            : '#';
    }

    /**
     * Sets the URL to the current translation workflow step.
     */
    public function setBackToTranslationUrl()
    {
        Yii::app()->session['backToTranslationUrl'] = Yii::app()->request->url;
    }

    /**
     * Returns the item description if available.
     * @return string|null the item description.
     */
    public function getItemDescription()
    {
        $model = new $this->modelClass;

        if (isset($model->itemDescription)) {
            return $model->itemDescription;
        } else {
            return null;
        }
    }

    /**
     * Renders an item description tooltip.
     * @return string the tooltip HTML.
     */
    public function itemDescriptionTooltip()
    {
        $itemDescription = $this->getItemDescription();

        return isset($itemDescription)
            ? Html::hintTooltip($itemDescription, array(
                'placement' => TbHtml::TOOLTIP_PLACEMENT_BOTTOM,
            ))
            : '';
    }

    /**
     * Checks if the user can edit source language fields.
     * @return boolean
     */
    public function canEditSourceLanguage()
    {
        if ($this->action->id === 'translate') {
            if (Yii::app()->user->isAdmin()) {
                return true;
            }

            $group = 'GapminderInternal';

            $isEditor = PermissionHelper::groupHasAccount(array(
                'account_id' => $this->id,
                'group_id' => PermissionHelper::groupNameToId($group),
                'role_id' => PermissionHelper::roleNameToId('Group Editor'),
            ));

            $isTranslator = PermissionHelper::groupHasAccount(array(
                'account_id' => $this->id,
                'group_id' => PermissionHelper::groupNameToId($group),
                'role_id' => PermissionHelper::roleNameToId('GroupTranslator'),
            ));

            return $isEditor && !$isTranslator;
        } else {
            return true;
        }
    }

    /**
     * Checks if the given step matches the current step.
     * @param string $step
     * @return boolean
     */
    public function isStep($step)
    {
        if (isset($_GET['step'])) {
            return $_GET['step'] === $step;
        } else {
            return false;
        }
    }

    /**
     * Checks if the action is 'evaluate'.
     *
     * This is used to check if the context is related to the 'evaluate' action
     * since some view files are shared across controller actions.
     *
     * @return boolean
     */
    public function actionIsEvaluate()
    {
        // TODO: Could we simply use $this->action->id === 'evaluate' instead?
        return $this->_actionIsEvaluate;
    }

    /**
     * Returns a label for the current view action.
     * @return string
     */
    public function getViewActionLabel()
    {
        return $this->actionIsEvaluate() ? Yii::t('app', 'Evaluate') : Yii::t('app', 'View');
    }

    /**
     * Returns the breadcrumbs based on the given model.
     * @param ActiveRecord|ItemTrait $model
     * @return array
     */
    public function itemBreadcrumbs($model)
    {
        // Set item title
        if (isset($model->title)) {
            $itemTitle = $model->title;
        } else if (isset($model->name)) {
            $itemTitle = $model->name;
        } else {
            $itemTitle = $model->id;
        }

        $breadcrumbs = array();
        $breadcrumbs[Yii::t('app', $model->modelLabel, 2)] = array('browse');
        $breadcrumbs[$itemTitle] = array('view', 'id' => $model->id);

        switch ($this->action->id) {
            case ItemControllerConstants::ACTION_EDIT:
                $breadcrumbs[] = self::actionLabel(ItemControllerConstants::ACTION_EDIT);
                break;

            case ItemControllerConstants::ACTION_TRANSLATION_OVERVIEW:
                $breadcrumbs[] = self::actionLabel(ItemControllerConstants::ACTION_TRANSLATION_OVERVIEW);
                break;

            case ItemControllerConstants::ACTION_TRANSLATE:
                $breadcrumbs[] = self::actionLabel(ItemControllerConstants::ACTION_TRANSLATE);
                break;

            case ItemControllerConstants::ACTION_EVALUATE:
                $breadcrumbs[] = self::actionLabel(ItemControllerConstants::ACTION_EVALUATE);
                break;

            case ItemControllerConstants::ACTION_PREPARE_FOR_REVIEW:
                $breadcrumbs[] = self::actionLabel(ItemControllerConstants::ACTION_PREPARE_FOR_REVIEW);
                break;

            case ItemControllerConstants::ACTION_PREPARE_FOR_PUBLISHING:
                $breadcrumbs[] = self::actionLabel(ItemControllerConstants::ACTION_PREPARE_FOR_PUBLISHING);
                break;

            case ItemControllerConstants::ACTION_PREVIEW:
                $breadcrumbs[] = self::actionLabel(ItemControllerConstants::ACTION_PREVIEW);
                break;

            default:
                $breadcrumbs[] = self::actionLabel(ItemControllerConstants::ACTION_VIEW);
        }

        return $breadcrumbs;
    }

    /**
     * Returns a label for the given controller action ID.
     * @param string $actionId
     * @return string
     */
    static public function actionLabel($actionId)
    {
        $defaultLabel = Yii::t('app', 'Action');

        $labels = array(
            ItemControllerConstants::ACTION_BROWSE => Yii::t('app', 'Browse'),
            ItemControllerConstants::ACTION_VIEW => Yii::t('app', 'View'),
            ItemControllerConstants::ACTION_ADD => Yii::t('app', 'Add'),
            ItemControllerConstants::ACTION_EDIT => Yii::t('app', 'Edit'),
            ItemControllerConstants::ACTION_CLONE => Yii::t('app', 'Clone'),
            ItemControllerConstants::ACTION_REMOVE => Yii::t('app', 'Remove'),
            ItemControllerConstants::ACTION_PREVIEW => Yii::t('app', 'Preview'),
            ItemControllerConstants::ACTION_TRANSLATE => Yii::t('app', 'Translate'),
            ItemControllerConstants::ACTION_TRANSLATION_OVERVIEW => Yii::t('app', 'Translate'),
            ItemControllerConstants::ACTION_EVALUATE => Yii::t('app', 'Evaluate'),
            ItemControllerConstants::ACTION_PROOFREAD => Yii::t('app', 'Proofread'),
            ItemControllerConstants::ACTION_APPROVE => Yii::t('app', 'Approve'),
            ItemControllerConstants::ACTION_PUBLISH => Yii::t('app', 'Publish'),
            ItemControllerConstants::ACTION_PREPARE_FOR_REVIEW => Yii::t('app', 'Prepare for Review'),
            ItemControllerConstants::ACTION_PREPARE_FOR_PUBLISHING => Yii::t('app', 'Prepare for Publishing'),
            ItemControllerConstants::ACTION_CANCEL => Yii::t('app', 'Cancel'),
        );

        return array_key_exists($actionId, $labels) ? $labels[$actionId] : $defaultLabel;
    }

}
