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

#include "rigelclient.hpp"

RigelClient::RigelClient(boost::asio::io_service &io_service) : RigelClient(io_service, "localhost", "82") {
}

RigelClient::RigelClient(boost::asio::io_service &io_service, string ipAddress, string portNum) : ipAddress(ipAddress),
                                                                                                  portNum(portNum),
                                                                                                  io_service(
                                                                                                          io_service),
                                                                                                  socket(io_service) {
    connect();
}


RigelClient::~RigelClient() {
    close();
}

void RigelClient::close() {
    io_service.post([this]() { socket.close(); });
}

void RigelClient::connect() {
    // Get a list of endpoints corresponding to the server name.
    boost::asio::ip::tcp::resolver resolver(io_service);
    auto endpoint_iterator = resolver.resolve({ipAddress, portNum});

    // Try each endpoint until we successfully establish a connection.
    socket.connect(*endpoint_iterator);
}


void RigelClient::sendPostRequest(string endPoint, string payload) {
    // Form the request. We specify the "Connection: close" header so that the
    // server will close the socket after transmitting the response. This will
    // allow us to treat all data up until the EOF as the content.
    boost::asio::streambuf request;
    std::ostream request_stream(&request);

    request_stream << "POST " << endPoint << " HTTP/1.1 \r\n";
    request_stream << "Host:" << ipAddress << ":" << portNum << "\r\n";
    request_stream << "Accept: application/json \r\n";
    request_stream << "Content-Type: application/json; charset=utf-8 \r\n";
    request_stream << "Content-Length: " << payload.length() << "\r\n";
    request_stream << "Connection: close\r\n\r\n";
    request_stream << payload;

    // Send the request.
    boost::asio::write(socket, request);
}

const string RigelClient::readResponse() {
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

    LOG_DEBUG("Response status: %s - %d - %s\n", http_version.c_str(), status_code, status_message.c_str())

    // Check that response is OK.
    if (!response_stream || http_version.substr(0, 5) != "HTTP/") {
        throw "Invalid response: " + to_string(status_code) + " " + status_message;
    }

    if (status_code != 200) {
        throw "Response not OK: " + to_string(status_code) + " " + status_message;
    }

    std::stringstream ostringstream_content;
    if (response.size() > 0) {
        ostringstream_content << &response;
    }
    boost::system::error_code error;
    while (true) {
        size_t n = boost::asio::read(socket, response, boost::asio::transfer_at_least(1), error);
        if (!error) {
            if (n) {
                ostringstream_content << &response;
            }
        }

        if (error == boost::asio::error::eof) {
            break;
        }
        if (error) {
            throw boost::system::system_error(error);
        }
    }

    return ostringstream_content.str();
}


const string getValidJsonString(vector<tuple<string, string>> data) {
    boost::property_tree::ptree ptree;

    for (auto &element : data)
        ptree.put(get<0>(element), get<1>(element));

    std::ostringstream jsonStream;
    write_json(jsonStream, ptree, false);
    std::string requestJson = jsonStream.str();

    return requestJson;
}