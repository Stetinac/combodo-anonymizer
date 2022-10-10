<?php
/**
 * @copyright   Copyright (C) 2010-2022 Combodo SARL
 * @license     http://opensource.org/licenses/AGPL-3.0
 */

namespace Combodo\iTop\Anonymizer\Action;

use BatchAnonymizationTaskAction;
use Combodo\iTop\Anonymizer\Helper\AnonymizerHelper;
use Combodo\iTop\Anonymizer\Helper\AnonymizerLog;
use Combodo\iTop\Anonymizer\Service\AnonymizerService;
use Combodo\iTop\Anonymizer\Service\CleanupService;
use Exception;
use MetaModel;
use MySQLHasGoneAwayException;

class CleanupUsers extends BatchAnonymizationTaskAction
{
	const USER_CLASS = 'User';

	/**
	 * @return void
	 * @throws \ArchivedObjectException
	 * @throws \CoreCannotSaveObjectException
	 * @throws \CoreException
	 * @throws \CoreUnexpectedValue
	 */
	public function InitActionParams()
	{
		$oTask = $this->GetTask();

		$aParams['iChunkSize'] = MetaModel::GetConfig()->GetModuleParameter(AnonymizerHelper::MODULE_NAME, 'max_chunk_size', 1000);

		$sId = $oTask->Get('id_to_anonymize');
		$oService = new AnonymizerService();
		$aParams['aUserIds'] = $oService->GetUserIdListFromContact($sId);
		$aParams['sCurrentUserId'] = reset($aParams['aUserIds']);

		$this->Set('action_params', json_encode($aParams));
		$this->DBWrite();
	}

	/**
	 * @return void
	 * @throws \ArchivedObjectException
	 * @throws \CoreCannotSaveObjectException
	 * @throws \CoreException
	 * @throws \CoreUnexpectedValue
	 */
	public function ChangeActionParamsOnError()
	{
		$aParams = json_decode($this->Get('action_params'), true);
		$iChunkSize = $aParams['iChunkSize'];
		if ($iChunkSize == 1) {
			AnonymizerLog::Debug('Stop retry action CleanupUsers with params '.json_encode($aParams));
			$this->Set('action_params', '');
			$this->DBWrite();

			return;
		}
		$aParams['iChunkSize'] = (int)$iChunkSize / 2 + 1;

		$this->Set('action_params', json_encode($aParams));
		$this->DBWrite();
	}

	/**
	 * Delete history entries, no need to keep track of the progress.
	 *
	 * @return bool
	 * @throws \ArchivedObjectException
	 * @throws \CoreCannotSaveObjectException
	 * @throws \CoreException
	 * @throws \CoreUnexpectedValue
	 * @throws \CoreWarning
	 * @throws \MySQLException
	 * @throws \OQLException
	 */
	public function ExecuteAction($iEndExecutionTime): bool
	{
		$oTask = $this->GetTask();

		$aParams = json_decode($this->Get('action_params'), true);
		$aContext = json_decode($oTask->Get('anonymization_context'), true);

		// Progress until the current user
		$iUserId = false;
		foreach ($aParams['aUserIds'] as $iUserId) {
			if ($iUserId === $aParams['sCurrentUserId']) {
				break;
			}
		}

		while ($iUserId !== false) {
			/** @var \User $oUser */
			$oUser = MetaModel::GetObject(self::USER_CLASS, $iUserId);
			$oService = new CleanupService(get_class($oUser), $iUserId, $iEndExecutionTime);
			// Disable User, reset login and password
			$oService->CleanupUser($oUser);
			if (!$oService->PurgeHistory($aParams['iChunkSize'])) {
				// Timeout stop here
				return false;
			}

			// Get all the request set to execute for every user
			$aRequests = $oService->GetCleanupChangesRequests($aContext);

			foreach ($aRequests as $sName => $aRequest) {
				$iProgress = $aParams['aChangesProgress'][$sName] ?? 0;
				$bCompleted = ($iProgress == -1);
				while (!$bCompleted && time() < $iEndExecutionTime) {
					try {
						$bCompleted = $oService->ExecuteActionWithQueriesByChunk($aRequest['select'], $aRequest['updates'], $aRequest['key'], $iProgress, $aParams['iChunkSize']);
						// Save progression
						$aParams['aChangesProgress'][$sName] = $iProgress;
						$this->Set('action_params', json_encode($aParams));
						$this->DBWrite();
					}
					catch (MySQLHasGoneAwayException $e) {
						//in this case retry is possible
						AnonymizerLog::Error('Error MySQLHasGoneAwayException during CleanupUsers try again later');

						return false;
					}
					catch (Exception $e) {
						AnonymizerLog::Error('Error during CleanupUsers with params '.$this->Get('action_params').' with message :'.$e->getMessage());
						AnonymizerLog::Error('Go to next update');
						$aParams['aChangesProgress'][$sName] = -1;
					}
					AnonymizerLog::Debug("ExecuteActionWithQueriesByChunk: name: $sName progress: $iProgress completed: $bCompleted");
				}
				if (!$bCompleted) {
					// Timeout
					return false;
				}
			}
			$aParams['aChangesProgress'] = [];
			$iUserId = next($aParams['aUserIds']);

			// Save progression
			$aParams['sCurrentUserId'] = $iUserId;
			$this->Set('action_params', json_encode($aParams));
			$this->DBWrite();
		}

		return true;
	}
}