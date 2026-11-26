{**
 * templates/settingsForm.tpl
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * PubMed Central plugin settings.
 *
 *}
<script type="text/javascript">
	$(function() {ldelim}
		// Attach the form handler.
		$('#pmcSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim})
</script>
<div class="legacyDefaults">
	<form class="pkp_form" method="post" id="pmcSettingsForm" action="{url router=PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" plugin="PubmedCentralExportPlugin" category="importexport" verb="save"}">
		{csrf}
		{include file="controllers/notification/inPlaceNotification.tpl" notificationId="pmcSettingsFormNotification"}
		{fbvFormArea id="pmcSettingsFormArea"}
			<p class="pkp_help">
				{translate key="plugins.importexport.pmc.description"}
			</p>
			<br/>
			{fbvFormSection list="true"}
				{fbvElement type="checkbox" id="automaticRegistration" label="plugins.importexport.pmc.settings.form.automaticRegistration.description" checked=$automaticRegistration|compare:true}
			{/fbvFormSection}

			{fbvFormSection}
				<span class="instruct">{translate key="plugins.importexport.pmc.settings.form.nlmTitle.description"}</span>
				<br/>
				{fbvElement type="text" required=true id="nlmTitle" value=$nlmTitle label="plugins.importexport.pmc.settings.form.nlmTitle" maxlength="100" size=$fbvStyles.size.MEDIUM}
			{/fbvFormSection}

			{capture assign="namingTypeTitle"}{translate key="plugins.importexport.pmc.settings.form.namingType"}{/capture}
			{fbvFormSection list=true title=$namingTypeTitle translate=false}
				{fbvElement type="radio" id="namingType-volumeIssue" name="namingType" value="volumeIssue" checked=$namingType|compare:"volumeIssue" label="plugins.importexport.pmc.settings.form.namingType.volumeIssue"}
				{fbvElement type="radio" id="namingType-articleNumber" name="namingType" value="articleNumber" checked=$namingType|compare:"articleNumber" label="plugins.importexport.pmc.settings.form.namingType.articleNumber"}
			{/fbvFormSection}

			{capture assign="sectionTitle"}{translate key="plugins.importexport.pmc.endpoint"}{/capture}
			{fbvFormSection id="formSection" title=$sectionTitle translate=false class="endpointContainer"}
				{if $hostNotPmc}
					<div class="pkp_notification">
						{include file="controllers/notification/inPlaceNotificationContent.tpl" notificationId="pmcHostNotPmc" notificationStyleClass="notifyWarning" notificationContents="plugins.importexport.pmc.hostNotPmc"|translate}
					</div>
				{/if}
				{fbvElement type="text" id="host" value=$host label="plugins.importexport.pmc.host" maxlength="120" size=$fbvStyles.size.MEDIUM}
				{fbvElement type="text" id="port" value=$port label="plugins.importexport.pmc.port" maxlength="5" size=$fbvStyles.size.MEDIUM}
				{fbvElement type="text" id="path" value=$path label="plugins.importexport.pmc.path" maxlength="120" size=$fbvStyles.size.MEDIUM}
				{fbvElement type="text" id="username" value=$username label="plugins.importexport.pmc.username" maxlength="120" size=$fbvStyles.size.MEDIUM}
				{if $passwordSaved}{assign var="passwordLabel" value="plugins.importexport.pmc.passwordSaved"}{else}{assign var="passwordLabel" value="plugins.importexport.pmc.password"}{/if}
				{fbvElement type="text" password=true id="password" autocomplete="off" label=$passwordLabel maxlength="120" size=$fbvStyles.size.MEDIUM}
			{/fbvFormSection}

			{capture assign="hostKeyTitle"}{translate key="plugins.importexport.pmc.settings.form.hostKey"}{/capture}
			{fbvFormSection title=$hostKeyTitle translate=false}
				<p class="pkp_help">{translate key="plugins.importexport.pmc.settings.form.hostKey.description"}</p>
			{/fbvFormSection}
			{fbvFormSection}
				{fbvElement type="text" id="hostKeyFingerprint" value=$hostKeyFingerprint label="plugins.importexport.pmc.settings.form.hostKeyFingerprint" maxlength="120" size=$fbvStyles.size.MEDIUM}
			{/fbvFormSection}
			{if $recordedHostKeyFingerprint}
				{fbvFormSection}
					<div class="description">{translate key="plugins.importexport.pmc.settings.form.hostKey.recorded" host=$recordedHostKeyHost|escape}</div>
					<code>{$recordedHostKeyFingerprint|escape}</code>
				{/fbvFormSection}
				{fbvFormSection}
					<div class="description">{translate key="plugins.importexport.pmc.settings.form.hostKey.compare"}</div>
					<code>{$hostKeyScanCommand|escape}</code>
				{/fbvFormSection}
				{fbvFormSection}
					<button type="button" id="pmcSettingsForm-clearHostKey" class="pkp_button pkp_button_offset">{translate key="plugins.importexport.pmc.settings.form.clearHostKey"}</button>
					{include file="linkAction/buttonGenericLinkAction.tpl" action=$clearHostKeyLinkAction buttonSelector="#pmcSettingsForm-clearHostKey"}
				{/fbvFormSection}
			{else}
				{fbvFormSection}
					<div class="description">{translate key="plugins.importexport.pmc.settings.form.hostKey.none"}</div>
				{/fbvFormSection}
			{/if}
		{/fbvFormArea}
		{fbvFormButtons submitText="common.save" hideCancel="true"}
		<p>
			<span class="formRequired">{translate key="common.requiredField"}</span>
		</p>
	</form>
</div>
