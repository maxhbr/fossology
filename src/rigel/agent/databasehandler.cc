/*
 * Copyright (C) 2014-2015, Siemens AG
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

#include "databasehandler.hpp"
#include "libfossUtils.hpp"

#include <iostream>

using namespace fo;
using namespace std;

RigelDatabaseHandler::RigelDatabaseHandler(DbManager dbManager) :
        fo::AgentDatabaseHandler(dbManager) {
}

vector<unsigned long> RigelDatabaseHandler::queryFileIdsForUpload(int uploadId) {
    return queryFileIdsVectorForUpload(uploadId);
}

// TODO: see function saveToDb() from src/monk/agent/database.c
bool RigelDatabaseHandler::saveLicenseMatch(int agentId, long pFileId, long licenseId) {
    return dbManager.execPrepared(
            fo_dbManager_PrepareStamement(
                    dbManager.getStruct_dbManager(),
                    "saveLicenseMatch",
                    "INSERT INTO license_file (agent_fk, pfile_fk, rf_fk) VALUES ($1, $2, $3)",
                    int, long, long
            ),
            agentId,
            pFileId,
            licenseId
    );
}

unsigned long RigelDatabaseHandler::selectLicenseIdForName(std::string const &rfShortName) {
    bool success = false;
    unsigned long result = 0;

    unsigned count = 0;
    while ((!success) && count++ < 3) {
        if (!dbManager.begin())
            continue;

        dbManager.queryPrintf("LOCK TABLE license_ref");

        QueryResult queryResult = dbManager.execPrepared(
                fo_dbManager_PrepareStamement(
                        dbManager.getStruct_dbManager(),
                        "selectLicenseIdForName",
                        "SELECT rf_pk FROM ONLY license_ref"
                        " WHERE rf_shortname = $1",
                        char *
                ),
                rfShortName.c_str()
        );

        success = queryResult && queryResult.getRowCount() > 0;

        if (success) {
            success &= dbManager.commit();

            if (success) {
                result = queryResult.getSimpleResults(0, fo::stringToUnsignedLong)[0];
            }
        } else {
            dbManager.rollback();
        }
    }

    return result;
}


unsigned long
RigelDatabaseHandler::insertLicenseIdForName(std::string const &rfShortName, std::string const &licenseText) {
    bool success = false;
    unsigned long result = 0;

    unsigned count = 0;
    while ((!success) && count++ < 3) {
        if (!dbManager.begin())
            continue;

        dbManager.queryPrintf("LOCK TABLE license_ref");

        QueryResult queryResult = dbManager.execPrepared(
                fo_dbManager_PrepareStamement(
                        dbManager.getStruct_dbManager(),
                        "insertLicenseIdForName",
                        "insertNew AS ("
                        "INSERT INTO license_ref(rf_shortname, rf_text, rf_detector_type)"
                        " SELECT $1, $2, $3"
                        " WHERE NOT EXISTS(SELECT * FROM selectExisting)"
                        " RETURNING rf_pk"
                        ") "
                        "SELECT rf_pk FROM insertNew ",
                        char*, char*, int
                ),
                rfShortName.c_str(),
                licenseText.c_str(),
                3
        );

        success = queryResult && queryResult.getRowCount() > 0;

        if (success) {
            success &= dbManager.commit();

            if (success) {
                result = queryResult.getSimpleResults(0, fo::stringToUnsignedLong)[0];
            }
        } else {
            dbManager.rollback();
        }
    }

    return result;
}


RigelDatabaseHandler RigelDatabaseHandler::spawn() const {
    DbManager spawnedDbMan(dbManager.spawn());
    return RigelDatabaseHandler(spawnedDbMan);
}

void RigelDatabaseHandler::insertOrCacheLicenseIdForName(string const &rfShortName) {
    if (getCachedLicenseIdForName(rfShortName) == 0) {
        unsigned long licenseId = selectLicenseIdForName(rfShortName);
        if (licenseId == 0) {
            // TODO get license text from rigel-server
            licenseId = insertLicenseIdForName(rfShortName, "License by Rigel");
        }

        if (licenseId > 0) {
            licenseRefCache.insert(std::make_pair(rfShortName, licenseId));
        }
    }
}

long RigelDatabaseHandler::getCachedLicenseIdForName(string const &rfShortName) const {
    auto findIterator = licenseRefCache.find(rfShortName);
    if (findIterator != licenseRefCache.end()) {
        return findIterator->second;
    } else {
        return 0;
    }
}
