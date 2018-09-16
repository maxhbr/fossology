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

#include <iostream>
#include <boost/asio.hpp>
#include <boost/tokenizer.hpp>
#include "rigelwrapper.hpp"
#include "utils.hpp"
#include <boost/property_tree/ptree.hpp>
#include <boost/property_tree/json_parser.hpp>
#include <boost/property_tree/ini_parser.hpp>
#include <boost/iostreams/stream.hpp>
#include <boost/foreach.hpp>
#include <boost/regex.hpp>

using boost::asio::ip::tcp;
using boost::property_tree::ptree; using boost::property_tree::read_json; using boost::property_tree::write_json;


string scanFileWithRigel(const State &state, const fo::File &file) {
    string result;

    try {
        // create request payload as JSON
        ptree requestPtree;

        requestPtree.put("text", file.getContent());
        requestPtree.put("file-type", getMimeType(file.getFileName()));
//        requestPtree.put("text",
//                         "The contents of this file are subject to the terms of either the GNU General Public License Version 2 only (GPL) or the Common Development and Distribution License collectively,the License).");

        std::ostringstream requestJsonStream;
        write_json(requestJsonStream, requestPtree, false);
        std::string requestJson = requestJsonStream.str();

        std::cout << "DEBUG: Request:\n" << requestJson << "\n";

        boost::asio::io_service io_service;
        string ipAddress = "10.0.2.2"; //"localhost" for loop back or ip address otherwise, i.e.- www.boost.org;
        string portNum = "5000";
        string endpoint = "/model";
        string hostAddress = ipAddress + ":" + portNum;

        // Get a list of endpoints corresponding to the server name.
        tcp::resolver resolver(io_service);
        tcp::resolver::query query(ipAddress, portNum);
        tcp::resolver::iterator endpoint_iterator = resolver.resolve(query);

        // Try each endpoint until we successfully establish a connection.
        tcp::socket socket(io_service);
        boost::asio::connect(socket, endpoint_iterator);

        // Form the request. We specify the "Connection: close" header so that the
        // server will close the socket after transmitting the response. This will
        // allow us to treat all data up until the EOF as the content.
        boost::asio::streambuf request;
        std::ostream request_stream(&request);
//        request_stream << "GET " << endpoint << " HTTP/1.1\r\n";  // note that you can change it if you wish to HTTP/1.0
//        request_stream << "Host: " << hostAddress << "\r\n";
//        request_stream << "Accept: */*\r\n";
//        request_stream << "Connection: close\r\n\r\n";

        request_stream << "POST /predict HTTP/1.1 \r\n";
        request_stream << "Host:" << hostAddress << "\r\n";
        request_stream << "Accept: application/json \r\n";
        request_stream << "Content-Type: application/json; charset=utf-8 \r\n";
        request_stream << "Content-Length: " << requestJson.length() << "\r\n";
        request_stream << "Connection: close\r\n\r\n";
        request_stream << requestJson;

        // Send the request.
        boost::asio::write(socket, request);

        // Read the response status line. The response streambuf will automatically
        // grow to accommodate the entire line. The growth may be limited by passing
        // a maximum size to the streambuf constructor.
        boost::asio::streambuf response;
        boost::asio::read_until(socket, response, "\r\n");

        std::istream response_stream(&response);
        std::string http_version;
        response_stream >> http_version;
        unsigned int status_code;
        response_stream >> status_code;
        std::string status_message;
        std::getline(response_stream, status_message);

        std::cout << "Status:\n" << http_version << " - " << status_code << " - " << status_message << "\n";

        // Check that response is OK.
        if (!response_stream || http_version.substr(0, 5) != "HTTP/") {
            std::cerr << "invalid response";
            bail(1);
        }

        if (status_code != 200) {
            std::cerr << "response did not returned 200 but " << status_code;
            bail(1);
        }

        std::stringstream ostringstream_content;
        if (response.size() > 0) {
            ostringstream_content << &response;
            std::cout << "DEBUG: Reading 0 ...\n";
        }
        boost::system::error_code error;
        while (true) {
            size_t n = boost::asio::read(socket, response, boost::asio::transfer_at_least(1), error);
            if (!error) {
                if (n) {
                    ostringstream_content << &response;
                    std::cout << "DEBUG: Reading 1 ...\n";
                }
            }

            if (error == boost::asio::error::eof) {
                std::cout << "DEBUG: Finished reading\n";
                break;
            }
            if (error) {
                throw boost::system::system_error(error);
            }
        }

        result = ostringstream_content.str();
    }
    catch (std::exception &e) {
        std::cout << "Exception: " << e.what() << "\n";
        bail(1);
    }

    std::cout << "DEBUG: result:\n" << result << "\n";
    return result;
}


vector<string> extractLicensesFromRigelResult(string responseString) {
    boost::regex rgx("\\{(.+)\\]\\s?\\}");
    boost::smatch match;

    std::cout << "DEBUG: Response:\n" << responseString << "\n";

    if (boost::regex_search(responseString, match, rgx))
        std::cout << "Match: " << match[0] << "\n";

    stringstream jsonStringStream;
    jsonStringStream << match[0];

    // Read json.
    ptree responsePtree;
    read_json(jsonStringStream, responsePtree);

    vector<string> licenses;

    BOOST_FOREACH(boost::property_tree::ptree::value_type &v, responsePtree.get_child("licenses")) {
                    assert(v.first.empty()); // array elements have no names
                    licenses.push_back(v.second.data());
                }

    for (auto &license : licenses)
        std::cout << license << "\n";

    return licenses;
}

vector<string> mapAllLicensesFromRigelToFossology(vector<string> rigelLicenseNames) {
    std::cout << "DEBUG: We are in rigelwrapper.cc\n";
    vector<string> mappedNames;
    for (vector<string>::const_iterator it = rigelLicenseNames.begin(); it != rigelLicenseNames.end(); ++it) {
        const string &rigelLicenseName = *it;
        mappedNames.push_back(mapOneLicenseFromRigelToFossology(rigelLicenseName));
    }
    return mappedNames;
}


const string mapOneLicenseFromRigelToFossology(string name) {
    /*
    class ClassificationResult(Enum):
    SINGLE = "single"
    MULTI = "multi"
    NO_LICENSE = "no license"
    LICENSE = "license"
    UNCLASSIFIED = "unclassified license"*/

    if (name == "no license") return string("No_license_found");
    if (name == "unclassified license") return string("UnclassifiedLicense");
    if (name == "MULTI_LICENSE") return string("Dual-License");
    return name;
}
