<?php
/**
 * JGerman GitHub Bot Helper based on the Joomla! Framework
 *
 * @copyright  Copyright (C) 2020 J!German (www.jgerman.de) All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace joomlagerman\Helper;

use Joomla\Github\Github;
use Joomla\Registry\Registry;

/**
 * Class for github
 *
 * @since  1.0
 */
class GithubApiHelper
{
	/**
	 * The github object
	 *
	 * @var    Github
	 * @since  1.0
	 */
	private $github;

	/**
	 * The github object
	 *
	 * @var    string
	 * @since  1.0
	 */
	private $options;

	/**
	 * Constructor.
	 *
	 * @param   array  $githubOptions  The options for the Joomla\Github\Github object
	 * @param   array  $options        The options for the GithubApiHelper
	 *
	 * @since   1.0
	 */
	public function __construct($githubOptions, $options)
	{
		$this->options = $options ?: new Registry;

		// Setup the default user agent if not already set.
		if (!$this->getOption('userAgent'))
		{
			$this->setOption('userAgent', 'JGerman-Bot/1.0');
		}

		$this->github = new Github($githubOptions);
		$this->dataRootPath = ROOT_PATH . '/data/';
	}

	/**
	 * Returns the latest run date for the item
	 *
	 * @return  DateTime  A DateTime Object with the latest run date
	 *
	 * @since   1.0
	 */
	public function getLatestRunDateTime(): \DateTime
	{
		$dataFileName = $this->getDateFileName();

		// When there is no file create one with an empty date so it is now.
		if (!is_file($dataFileName))
		{
			$now = new \DateTime('now');
			file_put_contents($dataFileName, $now->format('Y-m-d'));
		}

		return new \DateTime(file_get_contents($dataFileName));
	}

	/**
	 * Sets the latest run date to the given value
	 *
	 * @param   DateTime  $lastRunDateTime  The last run DateTime
	 *
	 * @return  void
	 *
	 * @since   1.0
	 */
	public function setLatestRunDateTime($lastRunDateTime): void
	{
		$dataFileName = $this->getDateFileName();

		if (is_file($dataFileName))
		{
			unlink($dataFileName);
		}

		file_put_contents($dataFileName, $lastRunDateTime->format('Y-m-d'));
	}

	/**
	 * Returns the file name to save the latest run DataTime
	 *
	 * @return  string  The dataFile path
	 *
	 * @since   1.0
	 */
	public function getDateFileName(): string
	{
		return $this->dataRootPath . 'lastrun.data';
	}

	/**
	 * Get an option from the instance.
	 *
	 * @param   string  $key  The name of the option to get.
	 *
	 * @return  mixed  The option value.
	 *
	 * @since   1.0
	 */
	public function getOption($key)
	{
		return isset($this->options[$key]) ? $this->options[$key] : null;
	}

	/**
	 * Set an option for the instance.
	 *
	 * @param   string  $key    The name of the option to set.
	 * @param   mixed   $value  The option value to set.
	 *
	 * @return  GithubApiHelper  This object for method chaining.
	 *
	 * @since   1.0
	 */
	public function setOption($key, $value)
	{
		$this->options[$key] = $value;

		return $this;
	}

	/**
	 * Get all closed and merged PRs with the translation label since a given timestamp
	 *
	 * @param   DateTime  $since  The timestamp since we want new data
	 *
	 * @return  array  An array of github Issue objects
	 *
	 * @since   1.0
	 */
	public function getClosedAndMergedTranslationIssuesList($since): array
	{
		// Get all closed issues with the translation label
		$closedIssues = $this->getClosedTranslationIssuesList($since);
		$closedAndMerged = [];

		foreach ($closedIssues as $issue)
		{
			$closedAt = new \DateTime($issue->closed_at);

			// Make sure only the closedAt date is checked and we ignore any additional comments or other updates to the issue
			if ($closedAt->format('Y-m-d') !== $since->format('Y-m-d'))
			{
				continue;
			}

			if ($this->github->pulls->isMerged($this->getOption('source.owner'), $this->getOption('source.repo'), $issue->number))
			{
				$closedAndMerged[] = $issue;
			}
		}

		return $closedAndMerged;
	}

	/**
	 * Get all closed issues with the translation label since a given timestamp
	 *
	 * @param   DateTime  $since  The timestamp since we want new data
	 *
	 * @return  array  an array of github Issue objects
	 *
	 * @since   1.0
	 */
	private function getClosedTranslationIssuesList($since)
	{
		// List all closed issues with the watchlabel
		$state  = 'closed';
		$labels = urlencode($this->getOption('source.watchlabel'));

		return $this->github->issues->getListByRepository(
			$this->getOption('source.owner'), $this->getOption('source.repo'), NULL, $state, NULL, NULL, $labels, NULL, NULL, $since
		);
	}

	/**
	 * Get an pull from the source github owner/repo
	 *
	 * @param   string  $issueId  The issue id
	 *
	 * @return  object
	 *
	 * @since   1.0
	 */
	public function getSourcePull($issueId)
	{
		return $this->github->pulls->get($this->getOption('source.owner'), $this->getOption('source.repo'), $issueId);
	}

	/**
	 * Creates an translation request issue
	 *
	 * @param   object  $sourceTranslationIssue  The sourceTranslationIssue Object
	 *
	 * @return  boolean  True on success false on failiure
	 *
	 * @since   1.0
	 */
	public function createNewTranslationRequestIssueFromMergedTranslationIssue($sourceTranslationIssue)
	{
		// Labels
		$labels[] = $this->getOption('translation.label');

		$sourcePull = $this->getSourcePull($sourceTranslationIssue->number);
		$labels[]   = $this->getTranslationTargetBranchLabel($sourcePull->base->ref);

		$body = $this->getOption('translation.templagebody');
		$body = str_replace('[sourcePullRequestUrl]', $sourcePull->_links->html->href, $body);

		// Create the issue in the translation owner/repo
		return $this->github->issues->create(
			$this->getOption('translation.owner'),
			$this->getOption('translation.repo'),
			$sourceTranslationIssue->title,
			$body,
			NULL,
			NULL,
			$labels,
			$this->getOption('translation.assigments')
		);
	}

	/**
	 * Returns the correct target label for a given target branch
	 *
	 * @param   string  $targetBranch  The branch target of the source repo
	 *
	 * @return  string  The label
	 *
	 * @since   1.0
	 */
	private function getTranslationTargetBranchLabel($targetBranch)
	{
		if ($targetBranch === 'staging')
		{
			return 'Joomla! 3.x';
		}

		return 'Joomla! ' . substr($targetBranch, 0, 1) . '.x';
	}
}
