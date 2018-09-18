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

#ifndef RIGEL_AGENT_DATABASE_HANDLER_HPP
#define RIGEL_AGENT_DATABASE_HANDLER_HPP

#include <string>
#include <vector>
#include <unordered_map>
#include "libfossAgentDatabaseHandler.hpp"
#include "libfossdbmanagerclass.hpp"

class RigelDatabaseHandler : public fo::AgentDatabaseHandler
{
public:
  RigelDatabaseHandler(fo::DbManager dbManager);
  RigelDatabaseHandler(RigelDatabaseHandler&& other) : fo::AgentDatabaseHandler(std::move(other)) {};
  RigelDatabaseHandler spawn() const;

  std::vector<unsigned long> queryFileIdsForUpload(int uploadId);
  bool saveLicenseMatch(int agentId, long pFileId, long licenseId);

  void insertOrCacheLicenseIdForName(std::string const& rfShortName);
  long getCachedLicenseIdForName(std::string const& rfShortName) const;

private:
  unsigned long selectLicenseIdForName(std::string const& rfShortName);
  unsigned long insertLicenseIdForName(std::string const& rfShortName, std::string const& licenseText);

  std::unordered_map<std::string,long> licenseRefCache;
};

#endif // RIGEL_AGENT_DATABASE_HANDLER_HPP
