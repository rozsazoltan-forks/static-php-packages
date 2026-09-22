#!/usr/bin/env python3
"""Prepare a local-only PTS directory from an existing official PHPBench installation."""

import argparse
import hashlib
from pathlib import Path
import shutil
import xml.etree.ElementTree as ET
import zipfile


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('existing_pts', type=Path, help='existing PTS user directory containing pts/phpbench-1.1.6')
    parser.add_argument('output', type=Path, help='new isolated PTS user directory')
    args = parser.parse_args()
    source, root = args.existing_pts.resolve(), args.output.resolve()
    profile = Path('pts/phpbench-1.1.6')
    archive = source / 'installed-tests' / profile / 'phpbench-081-patched2.zip'
    expected = '32503bd4ace0c8429493de864ca48bb16febed867e52b75f4369d7145f797718'
    if hashlib.sha256(archive.read_bytes()).hexdigest() != expected:
        raise RuntimeError('PHPBench archive differs from the official profile SHA256')
    root.mkdir(parents=True, exist_ok=False)
    shutil.copytree(source / 'test-profiles' / profile, root / 'test-profiles' / profile)
    install = root / 'installed-tests' / profile
    install.mkdir(parents=True)
    shutil.copy2(archive, install / archive.name)
    with zipfile.ZipFile(archive) as compressed:
        compressed.extractall(install)
    for name in ['phpbench', 'pts-install.json']:
        shutil.copy2(source / 'installed-tests' / profile / name, install / name)
    settings = {
        'OpenBenchmarking': {'AnonymousUsageReporting': 'FALSE', 'AllowResultUploadsToOpenBenchmarking': 'FALSE',
                            'AlwaysUploadSystemLogs': 'FALSE'},
        'General': {'ColoredConsole': 'FALSE', 'UsePhodeviCache': 'TRUE', 'DefaultDisplayMode': 'BATCH'},
        'Modules': {'AutoLoadModules': ''},
        'Installation': {'EnvironmentDirectory': str(root / 'installed-tests') + '/',
                         'CacheDirectory': str(root / 'download-cache') + '/', 'RemoveDownloadFiles': 'FALSE'},
        'Testing': {'ResultsDirectory': str(root / 'test-results') + '/', 'SaveSystemLogs': 'FALSE',
                    'SaveTestLogs': 'TRUE', 'SaveInstallationLogs': 'FALSE',
                    'AlwaysUploadResultsToOpenBenchmarking': 'FALSE', 'RemoveTestInstallOnCompletion': 'FALSE',
                    'ShowPostRunStatistics': 'FALSE'},
        'TestResultValidation': {'DynamicRunCount': 'FALSE', 'DropNoisyResults': 'FALSE'},
        'BatchMode': {'SaveResults': 'TRUE', 'OpenBrowser': 'FALSE', 'UploadResults': 'FALSE',
                      'PromptForTestIdentifier': 'FALSE', 'PromptForTestDescription': 'FALSE',
                      'PromptSaveName': 'FALSE', 'RunAllTestCombinations': 'TRUE', 'Configured': 'TRUE'},
        'Networking': {'NoInternetCommunication': 'TRUE', 'NoNetworkCommunication': 'TRUE'},
    }
    document = ET.Element('PhoronixTestSuite')
    options = ET.SubElement(document, 'Options')
    for section, values in settings.items():
        group = ET.SubElement(options, section)
        for key, value in values.items():
            ET.SubElement(group, key).text = value
    ET.indent(document)
    ET.ElementTree(document).write(root / 'user-config.xml', encoding='utf-8', xml_declaration=True)
    print(f'Prepared {root}; network communication and uploads are disabled.')


if __name__ == '__main__':
    main()
