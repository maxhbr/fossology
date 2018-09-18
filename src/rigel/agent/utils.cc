/*
 * Copyright (C) 2014-2018, Siemens AG
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * version 2 as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

#include <iostream>
#include "rigelwrapper.hpp"
#include "utils.hpp"

using namespace fo;

State getState(DbManager &dbManager) {
    int agentId = queryAgentId(dbManager);
    return {agentId};
}

int queryAgentId(DbManager &dbManager) {
    char *COMMIT_HASH = fo_sysconfig(AGENT_NAME, "COMMIT_HASH");
    char *VERSION = fo_sysconfig(AGENT_NAME, "VERSION");
    char *agentRevision;

    if (!asprintf(&agentRevision, "%s.%s", VERSION, COMMIT_HASH))
        bail(-1);

    int agentId = fo_GetAgentKey(dbManager.getConnection(), AGENT_NAME, 0, agentRevision, AGENT_DESC);
    free(agentRevision);

    if (agentId <= 0)
        bail(1);

    return agentId;
}

int writeARS(const State &state, int arsId, int uploadId, int success, DbManager &dbManager) {
    PGconn *connection = dbManager.getConnection();
    int agentId = state.getAgentId();

    return fo_WriteARS(connection, arsId, uploadId, agentId, AGENT_ARS, NULL, success);
}

void bail(int exitval) {
    fo_scheduler_disconnect(exitval);
    exit(exitval);
}

bool processUploadId(const State &state, int uploadId, RigelDatabaseHandler &databaseHandler) {
    vector<unsigned long> fileIds = databaseHandler.queryFileIdsForUpload(uploadId);

    bool errors = false;
#pragma omp parallel
    {
        RigelDatabaseHandler threadLocalDatabaseHandler(databaseHandler.spawn());

        size_t pFileCount = fileIds.size();
#pragma omp for
        for (size_t it = 0; it < pFileCount; ++it) {
            if (errors)
                continue;

            unsigned long pFileId = fileIds[it];

            if (pFileId == 0)
                continue;

            if (!matchPFileWithLicenses(state, pFileId, threadLocalDatabaseHandler)) {
                errors = true;
            }

            fo_scheduler_heart(1);
        }
    }

    return !errors;
}

bool matchPFileWithLicenses(const State &state, unsigned long pFileId, RigelDatabaseHandler &databaseHandler) {
    char *pFile = databaseHandler.getPFileNameForFileId(pFileId);

    if (!pFile) {
        LOG_ERROR("File not found: %lu\n", &pFileId);
        bail(8);
    }

    char *fileName = nullptr;
    {
#pragma omp critical (repo_mk_path)
        fileName = fo_RepMkPath("files", pFile);
    }
    if (fileName) {
        fo::File file(pFileId, fileName);

        if (!matchFileWithLicenses(state, file, databaseHandler))
            return false;

        free(fileName);
        free(pFile);
    } else {
        LOG_ERROR("PFile not found in repo: %lu\n", &pFileId);
        bail(7);
    }

    return true;
}

bool matchFileWithLicenses(const State &state, const fo::File &file, RigelDatabaseHandler &databaseHandler) {
    vector<string> mappedRigelLicenses = getLicensePredictionsFromRigel(state, file);
    return saveLicensesToDatabase(state, mappedRigelLicenses, file.getId(), databaseHandler);
}

bool saveLicensesToDatabase(const State &state, const vector<string> &licenses, unsigned long pFileId,
                            RigelDatabaseHandler &databaseHandler) {
    for (const auto &license : licenses) {
        databaseHandler.insertOrCacheLicenseIdForName(license);
    }

    if (!databaseHandler.begin())
        return false;

    for (const auto &license : licenses) {
        int agentId = state.getAgentId();

        unsigned long licenseId = databaseHandler.getCachedLicenseIdForName(license);

        if (licenseId == 0) {
            databaseHandler.rollback();
            LOG_ERROR("Cannot get licenseId for license: %s\n", license.c_str());
            return false;
        }

        if (!databaseHandler.saveLicenseMatch(agentId, pFileId, licenseId)) {
            databaseHandler.rollback();
            LOG_ERROR("Failed to save licenseMatch\n");
            return false;
        };
    }

    return databaseHandler.commit();
}

const string getMimeType(const string& filePath)
{
    string mimeType = "unknown mimetype";
    magic_t magicCookie = magic_open(MAGIC_MIME_TYPE);
    if (magicCookie == NULL)
    {
        LOG_FATAL("Failed to initialize magic cookie\n");
        return mimeType;
    }
    if (magic_load(magicCookie,NULL) != 0)
    {
        LOG_FATAL("Failed to load magic file: UnMagic\n");
        return mimeType;
    }

    mimeType = magic_file(magicCookie, filePath.c_str());
    LOG_VERBOSE0("Found mimetype by magic: '%s'", mimeType.c_str());

    magic_close(magicCookie);

    return mimeType;
}

