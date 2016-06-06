<?php
namespace Craft;

/**
 * Scheduler by Supercool
 *
 * @package   Scheduler
 * @author    Josh Angell
 * @copyright Copyright (c) 2016, Supercool Ltd
 * @link      http://plugins.supercooldesign.co.uk
 */

class Scheduler_JobsService extends BaseApplicationComponent
{
	private $_allJobIds;
	private $_jobsById;
	private $_fetchedAllJobs = false;

	/**
	 * Returns all of the API IDs.
	 *
	 * @return array
	 */
	public function getAllJobIds()
	{
		if (!isset($this->_allJobIds))
		{
			if ($this->_fetchedAllJobs)
			{
				$this->_allJobIds = array_keys($this->_jobsById);
			}
			else
			{
				$this->_allJobIds = craft()->db->createCommand()
					->select('id')
					->from('scheduler_jobs')
					->queryColumn();
			}
		}

		return $this->_allJobIds;
	}

	/**
	 * Returns all APIs.
	 *
	 * @param string|null $indexBy
	 * @return array
	 */
	public function getAllJobs($indexBy = null)
	{
		if (!$this->_fetchedAllJobs)
		{
			$jobRecords = Scheduler_JobRecord::model()->ordered()->findAll();
			$this->_jobsById = Scheduler_JobModel::populateModels($jobRecords, 'id');
			$this->_fetchedAllJobs = true;
		}

		if ($indexBy == 'id')
		{
			return $this->_jobsById;
		}
		else if (!$indexBy)
		{
			return array_values($this->_jobsById);
		}
		else
		{
			$jobs = array();

			foreach ($this->_jobsById as $job)
			{
				$jobs[$job->$indexBy] = $job;
			}

			return $jobs;
		}
	}

	/**
	 * Returns an API by its ID.
	 *
	 * @param $jobId
	 * @return Scheduler_JobModel|null
	 */
	public function getJobById($jobId)
	{

		if (!isset($this->_jobsById) || !array_key_exists($jobId, $this->_jobsById))
		{
			$jobRecord = Scheduler_JobRecord::model()->findById($jobId);

			if ($jobRecord)
			{
				$this->_jobsById[$jobId] = Scheduler_JobModel::populateModel($jobRecord);
			}
			else
			{
				$this->_jobsById[$jobId] = null;
			}
		}

		return $this->_jobsById[$jobId];
	}


	/**
	 * Simply takes our job model and passes it to save unless ther is a job with
	 * the same type and settings, in which case it just updates that jobs date
	 *
	 * @param Scheduler_JobModel $job
	 */
	public function addJob(Scheduler_JobModel $job)
	{

		$existingJob = Scheduler_JobRecord::model()->findByAttributes(array(
			'type'     => $job->type,
			'settings' => JsonHelper::encode($job->settings),
		));

		// If there is an existing job, update the model with its id
		if ($existingJob) {
			$job->id = $existingJob->id;
			$this->saveJob($job);

		// Other wise just save the new one
		} else {
			$this->saveJob($job);
		}

	}


	/**
	 * Saves a Job
	 *
	 * @param Scheduler_JobModel $job
	 * @throws \Exception
	 * @return bool
	 */
	public function saveJob(Scheduler_JobModel $job)
	{

		if ($job->id)
		{
			$jobRecord = Scheduler_JobRecord::model()->findById($job->id);

			if (!$jobRecord)
			{
				throw new Exception(Craft::t('No Job exists with the ID “{id}”', array('id' => $job->id)));
			}

			$oldJob = Scheduler_JobModel::populateModel($jobRecord);
			$isNewJob = false;
		}
		else
		{
			$jobRecord = new Scheduler_JobRecord();
			$isNewJob = true;
		}

		$jobRecord->type     = $job->type;
		$jobRecord->date     = $job->date;
		$jobRecord->settings = $job->settings;

		$jobRecord->validate();
		$job->addErrors($jobRecord->getErrors());


		if (!$job->hasErrors())
		{
			$transaction = craft()->db->getCurrentTransaction() === null ? craft()->db->beginTransaction() : null;
			try
			{

				// Save it!
				$jobRecord->save(false);

				// Now that we have an Job ID, save it on the model
				if (!$job->id)
				{
					$job->id = $jobRecord->id;
				}

				// Might as well update our cache of the Job while we have it.
				$this->_jobsById[$job->id] = $job;

				if ($transaction !== null)
				{
					$transaction->commit();
				}
			}
			catch (\Exception $e)
			{
				if ($transaction !== null)
				{
					$transaction->rollback();
				}

				throw $e;
			}

			return true;
		}
		else
		{
			return false;
		}
	}

	/**
	 * Deletes a Job
	 *
	 * @param int $jobId
	 * @throws \Exception
	 * @return bool
	 */
	public function deleteJobById($jobId)
	{
		if (!$jobId)
		{
			return false;
		}

		$transaction = craft()->db->getCurrentTransaction() === null ? craft()->db->beginTransaction() : null;
		try
		{

			$affectedRows = craft()->db->createCommand()->delete('scheduler_jobs', array('id' => $jobId));

			if ($transaction !== null)
			{
				$transaction->commit();
			}

			return (bool) $affectedRows;
		}
		catch (\Exception $e)
		{
			if ($transaction !== null)
			{
				$transaction->rollback();
			}

			throw $e;
		}
	}

}
