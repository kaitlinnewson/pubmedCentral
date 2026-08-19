# PubMed Central Plugin for OJS

An OJS plugin for exporting articles to [PubMed Central](https://pmc.ncbi.nlm.nih.gov/).

## Compatibility

Compatible with OJS 3.6 and later.

## Installation

### For Development

- Copy the plugin files to `plugins/generic/pubmedCentral/`
- Run the installation tool: `php lib/pkp/tools/installPluginVersion.php plugins/generic/pubmedCentral/version.xml`

## Using the Plugin

Before using this plugin, your journal should be approved for deposit by PubMed Central.

To use the plugin, ensure that your journal has entered a publisher and at least one ISSN in the journal settings.

Within the plugin settings, you will need to enter the PubMed Central FTP connection details and your journal's
NLM Title Abbreviation.

Articles to export to PubMed Central should meet the following requirements:

- Have a valid JATS XML file in OJS, or generate valid JATS (1.2) in OJS via the JATS Template plugin.
- Contain high-resolution image files, if applicable.
- Contain all required metadata (see below).

All JATS XML will be validated against its DTD and
[the PubMed Central Style Checker](https://pmc.ncbi.nlm.nih.gov/tools/stylechecker/) prior to export. It is not recommended
to disable validation for packages being sent to PubMed Central.

Exported packages will include a PDF galley of the article if one is available in the submission's primary language.
The plugin will add a link to the PDF in the JATS XML prior to export.

For more details about PubMed Central requirements, refer to the
[PubMed Central minimum data requirements](https://pmc.ncbi.nlm.nih.gov/pub/min_requirements/) and the
[PubMed Central Tagging Guidelines](https://pmc.ncbi.nlm.nih.gov/tagging-guidelines/article/style/).

### Naming Scheme

Journals may choose to use either volume/issue/page or the article number for naming the packages and files.

If the volume/issue/page naming scheme is used, then publications must be assigned to an issue and have volume, issue, and page numbers.

If article numbering is used, then publications must have an article number. Article number metadata can be enabled in
the settings under Settings → Distribution → Metadata → Article Number.

### DOI Versioning

If DOI versioning is enabled in OJS, then the user can deposit each major version of an article to PubMed Central.

## Uploaded JATS XML

If a publication has an uploaded JATS XML file, then that will be exported in the plugin instead of the OJS-generated JATS.
Uploaded JATS XML files will also be validated against the DTD and StyleChecker.

## Troubleshooting

If you are receiving validation errors, ensure you have entered the required metadata in your journal settings and/or
the publication. If you are using your own JATS XML documents, then you may need to modify the metadata in the JATS
XML to meet the requirements of PubMed Central.

## PubMed Central StyleChecker Updates

The StyleChecker validation files are periodically updated by PubMed Central. Updated file packages can be downloaded from
[the Downloadable StyleChecker page](https://pmc.ncbi.nlm.nih.gov/pub/stylechecker-info/) and placed in the `xsl` directory,
(`style-reporter.xsl` is not used and can be omitted).

## License

This plugin is licensed under the GNU General Public License v3.
