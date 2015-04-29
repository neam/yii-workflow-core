<?php

trait ItemTrait
{

    use TranslatableItemTrait;

    public $itemDescription;

    /**
     * Runtime cache for validation progress percentages.
     * @var array
     */
    public $validationProgress = array();

    /**
     * Returns the item label.
     * @return string
     */
    public function getItemLabel()
    {
        /** @var CActiveRecord $this */

        $classLabel = $this->getClassLabel();
        $label = Yii::t('app', $classLabel, 1) . ' #' . $this->id;

        return $label;
    }

    public function getClassLabel()
    {
        return get_class($this);
    }

    /**
     * @param array $scenarios Array of $scenarios to consider when calculating progress. If not specified, all scenarios will be recalculated, which may take quite some time
     */
    public function saveAppropriately($scenarios = null)
    {

        $behaviors = $this->behaviors();
        if (isset($behaviors['qa-state'])) {
            return $this->saveWithChangeSet($scenarios);
        } else {

            try {
                if (!$this->save()) {
                    throw new SaveException($this);
                }
            } catch (Exception $e) {
                $this->addError('id', $e->getMessage());
            }

        }

    }

    /**
     * @param array $scenarios Array of $scenarios to consider. If not specified, all scenarios will be recalculated, which may take quite some time
     */
    public function saveWithChangeSet($scenarios = null)
    {
        /** @var ActiveRecord|QaStateBehavior $model */
        $model = $this;

        // Refresh qa state (to be sure that we have the most actual state)
        $model->refreshQaState($scenarios);

        // Start transaction
        /** @var CDbTransaction $transaction */
        $transaction = Yii::app()->db->beginTransaction();

        try {

            $qsStates = array();
            $qsStates["before"] = $model->qaState()->attributes;

            // save
            if (!$model->save()) {
                throw new SaveException($model);
            }

            // refresh qa state
            $model->refreshQaState($scenarios);
            $qsStates["after"] = $model->qaState()->attributes;

            // calculate difference
            $qsStates["diff"] = array_diff_assoc($qsStates["before"], $qsStates["after"]);

            // log for dev purposes
            Yii::log("Changeset: " . print_r($qsStates, true), "flow", __METHOD__);

            // save changeset
            $changeset = new Changeset();
            $changeset->contents = json_encode($qsStates);
            $changeset->user_id = Yii::app()->user->id;
            $changeset->node_id = $model->ensureNode()->id;
            if (!$changeset->save()) {
                throw new SaveException($changeset);
            }

            // commit transaction
            $transaction->commit();

        } catch (Exception $e) {
            $model->addError('id', $e->getMessage());
            $transaction->rollback();
        }

    }

    /**
     * Checks if the item has a group.
     * @param string $group
     * @return boolean
     */
    public function belongsToGroup($group)
    {
        return PermissionHelper::nodeHasGroup(
            array(
                'node_id' => $this->node_id,
                'group_id' => PermissionHelper::groupNameToId($group),
            )
        );
    }

    /**
     * Make all related NodeHasGroup records visible.
     */
    public function makeNodeHasGroupVisible()
    {
        if (isset($this->node)) {
            $nhgs = $this->node->nodeHasGroups;

            if (!empty($nhgs)) {
                foreach ($nhgs as $nodeHasGroup) {
                    /** @var NodeHasGroup $nodeHasGroup */
                    $nodeHasGroup->makeVisible();
                }
            }
        }
    }

    /**
     * Make all related NodeHasGroup records hidden.
     */
    public function makeNodeHasGroupHidden()
    {
        if (isset($this->node)) {
            $nhgs = $this->node->nodeHasGroups;

            if (!empty($nhgs)) {
                foreach ($nhgs as $nodeHasGroup) {
                    /** @var NodeHasGroup $nodeHasGroup */
                    $nodeHasGroup->makeHidden();
                }
            }
        }
    }

    /**
     * @return array Status-dependent validation rules
     */
    public function statusRequirementsRules()
    {
        $statusRequirements = $this->definitionArrayWithSourceLanguageAttributes($this->statusRequirements());

        // Allow empty status requirements by defaulting to requiring "id"
        if (empty($statusRequirements['draft'])) {
            $statusRequirements['draft'] = array('id');
        }
        if (empty($statusRequirements['reviewable'])) {
            $statusRequirements['reviewable'] = array('id');
        }
        if (empty($statusRequirements['publishable'])) {
            $statusRequirements['publishable'] = array('id');
        }

        $statusRules = array();
        $statusRules['draft'] = array(
            implode(', ', $statusRequirements['draft']),
            'required',
            'on' => 'draft,reviewable,publishable'
        );
        $statusRules['reviewable'] = array(
            implode(', ', $statusRequirements['reviewable']),
            'required',
            'on' => 'reviewable,publishable'
        );
        $statusRules['publishable'] = array(
            implode(', ', $statusRequirements['publishable']),
            'required',
            'on' => 'publishable'
        );

        // Manual fields that are required
        $statusRules[] = array('status', 'validStatusReviewable', 'on' => 'status_reviewable');
        $statusRules[] = array('status', 'validStatusPublishable', 'on' => 'status_publishable');

        return $statusRules;
    }

    /**
     * Checks if the item can be published.
     * @return boolean
     */
    public function isPublishable()
    {
        // Obviously not publishable if not a preparable item
        if (!isset($this->{$this->_getQaStateAttribute()})) {
            return false;
        }

        /** @var QaStateBehavior $qaStateBehavior */
        $qaStateBehavior = $this->qaStateBehavior();

        return $qaStateBehavior->validStatus('publishable')
        && $this->belongsToAtLeastOneGroup()
        && !$this->isPublished();
    }

    /**
     * Checks if the item can be unpublished.
     * @return boolean
     */
    public function isUnpublishable()
    {
        return $this->belongsToAtLeastOneGroup()
        && $this->isPublished();
    }

    /**
     * Checks if the item has been published.
     * @return boolean
     */
    public function isPublished()
    {
        // TODO: Improve this check.
        return $this->isVisible();
    }

    /**
     * Checks if the item is visible (to anonymous users).
     * @return boolean
     */
    public function isVisible()
    {
        // If no node_id then it is not visible
        if (!isset($this->node_id)) {
            return false;
        }

        $result = NodeHasGroup::model()->findAllByAttributes(
            array(
                'node_id' => $this->node_id,
                'visibility' => PermissionHelper::VISIBILITY_VISIBLE,
            )
        );

        return !empty($result);
    }

    /**
     * Checks if the item belongs to at least one group.
     * @return boolean
     */
    public function belongsToAtLeastOneGroup()
    {
        // If no node_id then it does not belong to any group
        if (!isset($this->node_id)) {
            return false;
        }

        $result = NodeHasGroup::model()->findAllByAttributes(
            array(
                'node_id' => $this->node_id,
            )
        );

        return !empty($result);
    }

    /**
     * Changes a model's QA status.
     * @param string $status
     * @throws SaveException
     */
    public function changeStatus($status)
    {
        $qaState = $this->qaState();
        $qaState->status = $status;

        if (!$qaState->save()) {
            throw new SaveException($qaState);
        }
    }

    public function validStatusReviewable($attribute, $params)
    {
        if ($this->qaState()->allow_review != 1) {
            $this->addError($attribute, Yii::t('app', 'Reviewing not marked as allowed'));
        }
    }

    public function validStatusPublishable($attribute, $params)
    {
        if ($this->qaState()->allow_publish != 1) {
            $this->addError($attribute, Yii::t('app', 'Publishing not marked as allowed'));
        }
    }

    /**
     * Returns the validation progress percentage for the given scenario (checks the runtime cache).
     * @param string $scenario
     * @return integer
     */
    public function getValidationProgress($scenario)
    {
        if (isset($this->validationProgress[$scenario])) {
            return $this->validationProgress[$scenario];
        }

        $this->validationProgress[$scenario] = $this->calculateValidationProgress($scenario);
        return $this->validationProgress[$scenario];
    }

    public function flowStepRules()
    {
        // Metadata
        $flowSteps = $this->definitionArrayWithSourceLanguageAttributes($this->flowSteps());
        if (in_array(get_class($this), array_keys(ItemTypes::where('is_preparable')))) {
            $statusRequirements = $this->definitionArrayWithSourceLanguageAttributes($this->statusRequirements());
        } else {
            $statusRequirements = [];
        }

        // Combine above to flow/step-dependent validation rules
        $flowStepRules = array();
        foreach ($flowSteps as $step => $fields) {

            foreach ($fields as $field) {
                $onStatuses = array();
                $flowStepRules[] = array(
                    $field,
                    'safe',
                    'on' => implode(
                            "-step_$step,",
                            array('temporary', 'draft', 'reviewable', 'publishable')
                        ) . "-step_$step,step_$step"
                );
                if (isset($statusRequirements['draft']) && in_array($field, $statusRequirements['draft'])) {
                    $onStatuses = array('draft', 'reviewable', 'publishable');
                }
                if (isset($statusRequirements['reviewable']) && in_array($field, $statusRequirements['reviewable'])) {
                    $onStatuses = array('reviewable', 'publishable');
                }
                if (isset($statusRequirements['publishable']) && in_array($field, $statusRequirements['publishable'])) {
                    $onStatuses = array('publishable');
                }
                if (!empty($onStatuses)) {
                    $flowStepRules[] = array(
                        $field,
                        'required',
                        'on' => implode("-step_$step,", $onStatuses) . "-step_$step,step_$step"
                    );
                }
                $flowStepRules[] = array($field, 'required', 'on' => "step_$step-total_progress");
            }

        }

        return $flowStepRules;

    }

    /**
     * Returns invalid fields.
     * @param string $scenario
     * @return array
     */
    public function getInvalidFields($scenario)
    {
        // Work on a clone to not interfere with existing attributes and validation errors
        $model = clone $this;
        $model->scenario = $scenario;

        $attributes = $this->scenarioSpecificAttributes($scenario);
        $invalidFields = array();

        foreach ($attributes as $attribute) {
            $valid = $model->validate(array($attribute));

            if (!$valid) {
                $invalidFields[] = $attribute;
            }
        }

        return $invalidFields;
    }

    /**
     * Returns an action route.
     * @param string $action the controller action.
     * @param string $step
     * @param string|null $translateInto
     * @return array
     */
    public function getActionRoute($action, $step, $translateInto = null)
    {
        $route = array(
            $action,
            'id' => $this->{$this->tableSchema->primaryKey},
            'step' => $step,
        );

        if (!empty($translateInto)) {
            $route['translateInto'] = $translateInto;
        }

        return $route;
    }

    /**
     * Returns the *_qa_state_id attribute name of the current model.
     * @return string
     * @throws CException
     */
    public function _getQaStateAttribute()
    {
        $modelClass = get_class($this);
        $attribute = $this->tableName() . '_qa_state_id';
        return $attribute;
    }

    public function getQaStateAttribute()
    {
        $attribute = $this->_getQaStateAttribute();
        if ($this->hasAttribute($attribute)) {
            return $attribute;
        } else {
            throw new CException(get_class($this) . " does not have an attribute '$attribute'.");
        }
    }

    /**
     * Renders an item image.
     * @param string $p3preset
     * @return string the HTML.
     */
    public function renderImage($p3preset = 'dashboard-item-task-thumbnail')
    {
        $presetConfig = Yii::app()->getModule('p3media')->params['presets'][$p3preset];

        $width = $presetConfig['commands']['resize'][0];
        $height = $presetConfig['commands']['resize'][1];

        return isset($this->thumbnail_media_id)
            ? $this->thumbnailMedia->image($p3preset)
            : TbHtml::image("http://placehold.it/{$width}x{$height}");
    }

    /**
     * Checks an item models access relative to the user.
     * @param string $operation
     * @return boolean
     */
    public function checkAccess($operation)
    {
        return Yii::app()->user->checkAccess(
            get_class($this) . '.' . $operation,
            array('id' => $this->{$this->tableSchema->primaryKey})
        );
    }

    /**
     * Returns the first workflow step of this item.
     * @return string|null
     */
    public function firstFlowStep()
    {
        // Return the step defined in the model class (if it has been set).
        if (isset($this->firstFlowStep)) {
            return $this->firstFlowStep;
        }

        // Return the first index in the steps array.
        foreach ($this->flowSteps() as $step => $fields) {
            return $step;
        }

        return null;
    }

    /**
     * Returns the first step in the translation workflow. Falls back to ItemTrait::firstFlowStep().
     * @return string
     */
    public function firstTranslationFlowStep()
    {
        foreach ($this->flowSteps() as $step => $fields) {
            if (!$this->anyCurrentlyTranslatable($fields)) {
                continue;
            }
            return $step;
        }
    }

    public function getAttributeHint($key)
    {
        $a = $this->attributeHints();
        if (isset($a[$key])) {
            return $a[$key];
        }
    }

}
