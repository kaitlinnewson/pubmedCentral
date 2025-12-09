{**
 * templates/settingsForm.tpl
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * PubMed Central plugin settings
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
			<p class="pkp_help">{translate key="plugins.importexport.pmc.description"}</p>
			<br/>
			{fbvFormSection list="true"}
				{fbvElement type="checkbox" id="jatsImported" label="plugins.importexport.pmc.settings.form.jatsImportedOnly" checked=$jatsImported|compare:true}
			{/fbvFormSection}

			{fbvFormSection}
				<span class="instruct">{translate key="plugins.importexport.pmc.settings.form.nlmTitle.description"}</span><br/>
				{fbvElement type="text" id="nlmTitle" value=$nlmTitle label="plugins.importexport.pmc.settings.form.nlmTitle" maxlength="100" size=$fbvStyles.size.MEDIUM}
			{/fbvFormSection}

			{capture assign="sectionTitle"}{translate key="plugins.importexport.pmc.endpoint"}{/capture}
			{fbvFormSection id="formSection" title=$sectionTitle translate=false class="endpointContainer"}
				{fbvElement type="select" id="type" from=$endpointTypeOptions selected=$credentials.type label="plugins.importexport.pmc.endpoint.type" size=$fbvStyles.size.SMALL translate=false}
				<div class="endpointDetails">
					<div class="presetField ftp sftp">
						{fbvElement type="text" id="hostname" value=$credentials.hostname label="plugins.importexport.pmc.endpoint.hostname" maxlength="120" size=$fbvStyles.size.MEDIUM}
						{fbvElement type="text" id="port" value=$credentials.port label="plugins.importexport.pmc.endpoint.port" maxlength="5" size=$fbvStyles.size.MEDIUM}
						{fbvElement type="text" id="path" value=$credentials.path label="plugins.importexport.pmc.endpoint.path" maxlength="120" size=$fbvStyles.size.MEDIUM}
					</div>
					{fbvElement type="text" id="username" value=$credentials.username label="plugins.importexport.pmc.endpoint.username" maxlength="120" size=$fbvStyles.size.MEDIUM}
					<div class="authentication-password">
						{fbvElement type="text" password=true id="password" value=$credentials.password label="plugins.importexport.pmc.endpoint.password" maxlength="120" size=$fbvStyles.size.MEDIUM}
					</div>
				</div>
			{/fbvFormSection}
		{/fbvFormArea}
		{fbvFormButtons submitText="common.save" hideCancel="true"}
		<p>
			<span class="formRequired">{translate key="common.requiredField"}</span>
		</p>
	</form>
</div>
