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

#ifndef RIGEL_AGENT_RIGEL_CLIENT_HPP
#define RIGEL_AGENT_RIGEL_CLIENT_HPP

#include <string>
#include <iostream>
#include <boost/asio.hpp>
#include <boost/property_tree/ptree.hpp>
#include <boost/property_tree/json_parser.hpp>
#include <boost/property_tree/ini_parser.hpp>
#include <boost/iostreams/stream.hpp>
#include "utils.hpp"
#include <tuple>
#include <vector>

using namespace std;

class RigelClient {
public:
    RigelClient(boost::asio::io_service &io_service);
    RigelClient(boost::asio::io_service &io_service, string ipAddress, string portNum);
    ~RigelClient();

    void close();
    void sendPostRequest(string endPoint, string payload);
    const string readResponse();

private:
    const string ipAddress;
    const string portNum;
    boost::asio::io_service &io_service;
    boost::asio::ip::tcp::socket socket;

    void connect();
};

const string getValidJsonString(vector<tuple<string, string>> data);

#endif //FOSSOLOGY_HTTP_CLIENT_RIGELCLIENT_H

