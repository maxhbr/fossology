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
#include <boost/asio.hpp>
#include <boost/tokenizer.hpp>
#include "rigelwrapper.hpp"
#include "rigelclient.hpp"
#include <boost/foreach.hpp>
#include <boost/regex.hpp>

using boost::property_tree::ptree; using boost::property_tree::read_json; using boost::property_tree::write_json;


vector<string> getLicensePredictionsFromRigel(const State &state, const fo::File &file) {
    vector<string> licenses;
    try {
        vector<tuple<string, string>> requestData = {
                std::make_tuple("text", file.getContent()),
                std::make_tuple("fileType", getMimeType(file.getFileName()))
        };

        boost::asio::io_service io_service;
        RigelClient client(io_service);

        client.sendPostRequest("/predict", getValidJsonString(requestData));

        vector<string> rigelLicenseNames = extractLicensesFromRigelResponse(client.readResponse());
        licenses = mapAllLicensesFromRigelToFossology(rigelLicenseNames);
    }
    catch (std::exception &e) {
        LOG_ERROR("Exception: %s\n", e.what());
        bail(1);
    }
    return licenses;
}


vector<string> extractLicensesFromRigelResponse(string responseString) {
    vector<string> licenses;
    boost::regex rgx(R"(\{(.+)\]\s?\})");
    boost::smatch match;

    LOG_DEBUG("Response:\n%s\n", responseString.c_str())

    if (boost::regex_search(responseString, match, rgx)) {
        stringstream jsonStringStream;
        jsonStringStream << match[0];

        // Read json.
        ptree responsePtree;
        read_json(jsonStringStream, responsePtree);

        BOOST_FOREACH(boost::property_tree::ptree::value_type &v, responsePtree.get_child("licenses")) {
                        assert(v.first.empty());
                        licenses.push_back(v.second.data());
                    }
    }
    return licenses;
}


string getLicenseTextFromRigel(string licenseName) {
    try {
        vector<tuple<string, string>> requestData = {
                std::make_tuple("licenseName", licenseName),
        };

        boost::asio::io_service io_service;
        RigelClient client(io_service);

        client.sendPostRequest("/license", getValidJsonString(requestData));
        return extractLicenseTextFromRigelResponse(client.readResponse());
    }
    catch (std::exception &e) {
        LOG_ERROR("Caught exception (see below) but returning \"License by rigel\":\n %s\n", e.what());
        return "License by rigel";
    }
}


string extractLicenseTextFromRigelResponse(string responseString) {
    string licenseText = "License by rigel";
    boost::regex rgx(R"(\{(.+)\s?\})");
    boost::smatch match;

    LOG_DEBUG("Response:\n%s\n", responseString.c_str())

    if (boost::regex_search(responseString, match, rgx)) {
        stringstream jsonStringStream;
        jsonStringStream << match[0];

        ptree responsePtree;
        read_json(jsonStringStream, responsePtree);
        licenseText = responsePtree.get<string>("licenseText");
    }

    return licenseText;
}

vector<string> mapAllLicensesFromRigelToFossology(vector<string> rigelLicenseNames) {
    vector<string> mappedNames;
    for (vector<string>::const_iterator it = rigelLicenseNames.begin(); it != rigelLicenseNames.end(); ++it) {
        const string &rigelLicenseName = *it;
        mappedNames.push_back(mapOneLicenseFromRigelToFossology(rigelLicenseName));
    }
    return mappedNames;
}

const string mapOneLicenseFromRigelToFossology(string name) {
    if (name == "no license") return string("No_license_found");
    if (name == "unclassified license") return string("UnclassifiedLicense");
    if (name == "MULTI_LICENSE") return string("Dual-License");
    return name;
}
